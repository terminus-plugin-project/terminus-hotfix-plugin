<?php
/**
 * Terminus Plugin that adds command(s) to facilitate a hotfix workflow for [Pantheon](https://www.pantheon.io) sites.
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusHotFix\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Commands\Site\SiteCommand;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;
use Pantheon\Terminus\Commands\Backup\SingleBackupCommand;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Hot Fix Command
 * @package Pantheon\TerminusHotFix\Commands
 */
class HotFixCommand extends SingleBackupCommand implements RequestAwareInterface
{
    use RequestAwareTrait;

    private $options = array();
    private $site_env;
    private $multidev;
    private $site = array();
    private $envs = array();
    private $env = array();
    private $temp_dir;
    private $git_dir;
    private $git_url;
    private $git_branches;
    private $fs;
    
    /**
     * Returns the deployed git reference of the specifiied environment.
     * 
     * @authorize
     *
     * @command hotfix:env:git-ref
     * @param string $site_env Site and environment in the format `<site>.<env>`.
     */
    public function hotFixGitRef($site_env)
    {

        $site = $this->fetchSiteDetails($site_env);

        return $site['env']['git_ref'];

    }
    
    /**
     * Create a hotfix environment from either the test or live environments.
     * 
     * @authorize
     *
     * @command hotfix:env:create
     * @param string $site_env Site and source environment in the format `<site>.<env>`.
     * @param string $multidev Multidev environment name.
     * @param array $options
     * @option cleanup-temp-dir Delete the temporary directory used for code clone after cloning is complete. 
     * @usage <site>.<env> <multidev> Creates a new <multidev> environment for the site <site> with code, database and files from the <env> environment by checking out the lastest git tag associated with <env>.
     */
    public function hotFixEnvCreate(
            $site_env,
            $multidev='hotfix',
            $options = [
                'cleanup-temp-dir' => true,
            ]
        )
    {

        // Initialize class vars
        $this->initClassVars($site_env, $multidev, $options);

        // Bail if attempting to use a git branch that already exists on the remote
        if( in_array( $this->multidev, array_keys( $this->git_branches ), true ) ){
            if( in_array( $this->multidev, array_keys( $this->envs ), true ) ){
                throw new TerminusException(
                    'An environment for the provided multidev environment {multidev} already exists for the site {site}. Run {terminus_command} to delete it or choose a different multidev name and try again.', 
                    array(
                        'multidev' => $this->multidev,
                        'site' => $this->site['name'],
                        'terminus_command' => "terminus multidev:delete {$this->site['name']}.{$this->multidev} --delete-branch",
                    )
                );
            } else {
                throw new TerminusException(
                    'A git branch for the provided multidev environment {multidev} already exists for the site {site}. Please delete the remote git branch or choose a different multidev name and try again.', 
                    array(
                        'multidev' => $this->multidev,
                        'site' => $this->site['name'],
                    )
                );
            }
        }
        
        // Clone the site locally
        $this->deleteGitDir();
        $this->createTempDir();
        $this->log()->notice(
            'Cloning code for {site_env} to {git_dir}...',
            array(
                'site_env' => $site_env,
                'git_dir' => $this->git_dir,
            )
        );
        $this->passthru("git clone {$this->git_url} {$this->git_dir}");
        
        // Create the git branch locally and push to Pantheon
        $this->passthru("git -C {$this->git_dir} fetch --tags");
        $this->passthru("git -C {$this->git_dir} checkout {$this->env['git_ref']}");
        $this->passthru("git -C {$this->git_dir} checkout -b {$this->multidev}");
        $this->passthru("git -C {$this->git_dir} push -u origin {$this->multidev}");

        // Create the multidev
        $this->log()->notice(
            'Creating the {multidev} multidev environment on {site}...',
            [
                'multidev' => $this->multidev,
                'site' => $this->site['name'],
            ]
        );

        $workflow = $this->site['site_raw']->getEnvironments()->create($multidev, $this->env['env_raw']);
        while (!$workflow->checkProgress()) {
            // @TODO: Add Symfony progress bar to indicate that something is happening.
        }
        $this->log()->notice($workflow->getMessage());

        $this->deleteTempDir();

    }

    /**
     * Fetch details of a site from '<site>.<env>' format
     *
     * @param string $site_env
     * @return array
     */
    private function fetchSiteDetails($site_env){
        list($site, $env) = $this->getSiteEnv($site_env);

        $return = array( 
            'site' => array(),
            'env' => array(),
        );

        /**
         * Serialize provides
         * [id], [name], [label], [created], [framework], [organization], [service_level], [max_num_cdes], [upstream], [php_version], [holder_type], [holder_id], [owner], [frozen], [last_frozen_at]
         */
        $return['site'] = $site->serialize();
        $return['site']['site_raw'] = $site;
        // Turn the string value of 'true' or 'false' into a boolean
        $return['site']['frozen'] = filter_var($return['site']['frozen'], FILTER_VALIDATE_BOOLEAN);

        if( $return['site']['frozen'] ){
            // @todo: Ask the user if they want to unfreeze the site
            throw new TerminusException(
                'The requested site {site} is frozen.', 
                array(
                    'site' => $site['name']
                    )
            );
        }

        $target_ref = $env->get('target_ref');
        $target_ref = str_replace( ['refs/tags/', 'refs/heads/'], '', $target_ref );

        $return['env'] = array(
            'env_raw' => $env,
            'id' => $env->id,
            'url' => 'https://' . $env->id . '-' . $return['site']['name'] . '.pantheonsite.io/',
            'pantheon_domain' => $env->id . '-' . $return['site']['name'] . '.pantheonsite.io',
            'git_ref' => $target_ref,
        );

        return $return;
    }

    /**
     * Set class variables
     *
     * @param string $site_env
     * @return void
     */
    private function setClassVars($site_env) {
        $site_details = $this->fetchSiteDetails($site_env);
        $this->site = $site_details['site'];
        
        if( ! isset( $this->envs[$site_details['env']['id']] ) || empty( $this->envs[$site_details['env']['id']] ) ){
            $this->envs[$site_details['env']['id']] = $site_details['env'];
        }

        if( empty( $this->env ) ){
            $this->env = $site_details['env'];
        }
    }

    /**
     * Initialize class vars
     *
     * @param string $site_env
     * @return void
     */
    private function initClassVars($site_env, $multidev, $options=array()){
        // Make sure options are booleans and not strings
        if( ! empty($options) ){
            foreach( $options as $key => $value ){
                $options[$key] = boolval( $value );
                $this->options[$key] = boolval( $value );
            }
        }

        if( strlen($multidev) > 11 ){
            throw new TerminusException(
                'The provided multidev environment name {multidev} is longer than the allowed 11 characters', 
                array(
                    'multidev' => $multidev
                )
            );
        }

        // Fetch site details
        $this->$site_env = $site_env;
        $this->setClassVars($site_env);
        $this->multidev = $multidev;
        
        // Make sure default envs are populated with detailed info
        $default_envs = array ('dev', 'test', 'live');
        foreach( $default_envs as $default_env ){
            if( ! isset( $this->envs[$default_env] ) || empty( $this->envs[$default_env] ) ){
                $this->setClassVars("{$this->site['name']}.$default_env");
            }
        }

        // Populate all other environments with minimal info
        $all_envs = $this->site['site_raw']->getEnvironments()->serialize();
        foreach( array_keys( $all_envs ) as $current_env ){

            if( ! isset( $this->envs[$current_env] ) || empty( $this->envs[$current_env] ) ){
                $this->envs[$current_env] = $all_envs[$current_env];
            }
        }

        // Set the git variables
        $dev_connection_info = $this->envs['dev']['env_raw']->connectionInfo();
        $this->git_url = $dev_connection_info['git_url'];
        $this->git_branches = $this->site['site_raw']->getBranches()->serialize();
        
        // Set temp directories
        $this->temp_dir = sys_get_temp_dir() . '/terminus-hotfix-plugin-temp/';
        $this->git_dir = $this->temp_dir . $this->site['name'] . '/';

        // Symfony file system
        $this->fs = new Filesystem();
    }

    /**
     * Create the temp dir if needed
     *
     * @return void
     */
    private function createTempDir(){
        clearstatcache();

        // Create the temp directory
        if( ! file_exists( $this->temp_dir ) ){
            $this->log()->notice(
                'Creating the temporary {temp_dir} directory...',
                array(
                    'temp_dir' => $this->temp_dir,
                )
            );
            mkdir($this->temp_dir, 0700, true);
        }
    }
    
    /**
     * Delete the temp dir if needed
     *
     * @return void
     */
    private function deleteTempDir(){
        clearstatcache();

        if( $this->options['cleanup-temp-dir'] && file_exists( $this->temp_dir )  ){    
            $this->log()->notice(
                'Deleting the temporary {temp_dir} directory...',
                array(
                    'temp_dir' => $this->temp_dir,
                )
            );
            $this->fs->remove($this->temp_dir);
        }
    }
    
    /**
     * Delete the git dir if it exists
     *
     * @return void
     */
    private function deleteGitDir(){
        clearstatcache();

        if( file_exists( $this->git_dir ) ){
            $this->log()->notice(
                'Deleting the temporary {git_dir} directory...',
                array(
                    'git_dir' => $this->git_dir,
                )
            );

            $this->fs->remove($this->git_dir);
        }
    }

    /**
     * Call passthru; throw an exception on failure.
     *
     * @param string $command
     */
    protected function passthru($command, $loggedCommand = '')
    {
        $result = 0;
        $loggedCommand = empty($loggedCommand) ? $command : $loggedCommand;
        // TODO: How noisy do we want to be?
        $this->log()->notice("Running {cmd}", array('cmd' => $loggedCommand));
        passthru($command, $result);
        if ($result != 0) {
            throw new TerminusException(
                'Command {command} failed with exit code {status}', 
                array(
                    'command' => $loggedCommand, 'status' => $result
                )
            );
        }
    }

}
