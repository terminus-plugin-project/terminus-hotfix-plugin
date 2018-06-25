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
        $this->cloneSiteLocally();
        
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
     * Deploy a hotfix change from from a multidev environment to test or live and rebase to dev.
     * 
     * @authorize
     *
     * @command hotfix:env:deploy
     * @param string $site_env Site and the environment to deploy to in the format `<site>.<env>`.
     * @param string $multidev The source multidev environment name.
     * @param array $options
     * @option boolean $cleanup-temp-dir Delete the temporary directory used for code clone after cloning is complete.
     * @option string $cc Clear caches after deploy.
     * @option string $create-backup Create a backup before deploying  to <env> or rebasing to the master branch.
     * @option string $merge-strategy The git merge strategy to use when rebasing hotfix commits back to master.
     * @option string $message The annotation to use for the git tag when deploying the hotfix.
     * @usage <site>.<env> <multidev> Deploys the <multidev> environment hotfix changes for the site <site> to the specified <env>.
     */
    public function hotFixEnvDeploy(
            $site_env,
            $multidev='hotfix',
            $options = [
                'cleanup-temp-dir' => true,
                'create-backup' => false,
                'cc' => false,
                'merge-strategy' => "theirs",
                'message' => "Hotfix deployment",
            ]
        )
    {

        // Initialize class vars
        $this->initClassVars($site_env, $multidev, $options);

        // Bail if attempting to deploy to an environment other than test or live
        if( ! in_array( $this->env['id'], ['test', 'live'], true ) ) {
            throw new TerminusException(
                'You can not deploy a hotfix to {env}. Please try again with {test} or {live}.', 
                array(
                    'env' => $this->env['id'],
                    'test' => 'test',
                    'live' => 'live',
                )
            );
        }

        // Bail if attempting to deploy the hotfix from an invalid environment
        if( in_array( $this->multidev, ['test', 'live'], true ) ) {
            throw new TerminusException(
                'You can not deploy a hotfix from the {multidev} environment. You can only deploy a hotfix from the dev or a multidev environment.', 
                array(
                    'multidev' => $this->multidev,
                )
            );
        }

        // Bail if the requested multidev does not exist
        if( ! in_array( $this->multidev, array_keys( $this->envs ), true ) ){
            throw new TerminusException(
                'An environment for the provided multidev environment {multidev} could not be found for the site {site}. You can create one with {terminus_command}', 
                array(
                    'multidev' => $this->multidev,
                    'site' => $this->site['name'],
                    'terminus_command' => "terminus hotfix:env:create {$this->site['name']}.live {$this->multidev}",
                )
            );
        }

        // Clone the site locally
        $this->cloneSiteLocally();
        
        // Checkout the git branch locally
        $this->passthru("git -C {$this->git_dir} checkout {$this->multidev}");
        
        // Make sure we have all tags locally
        $this->passthru("git -C {$this->git_dir} fetch --tags");
        
        // Calculate the next tag
        $tag_prefix = "pantheon_{$this->env['id']}_";
        $current_tag_number = intval( str_replace( $tag_prefix, '', $this->env['git_ref'] ) );
        $next_tag_number = $current_tag_number + 1;
        $next_ref = $tag_prefix.$next_tag_number;
        
        // Create the next tag
        $this->log()->notice(
            'Creating the tag {next_ref} from the previous reference of {git_ref} on {site}...',
            [
                'site' => $this->site['name'],
                'git_ref' => $this->env['git_ref'],
                'next_ref' => $next_ref,
            ]
        );

        $this->passthru("git -C {$this->git_dir} tag -a {$next_ref} -m \"{$this->options['message']}\"");

        // Rebase changes from the branch back to master
        $this->log()->notice(
            'Rebasing the changes from {multidev} back to {master} with strategy {strategy} on {site}...',
            [
                'master' => 'master',
                'multidev' => $this->multidev,
                'site' => $this->site['name'],
                'strategy' => $this->options['merge-strategy'],
            ]
        );

        // Checkout master
        $this->passthru("git -C {$this->git_dir} checkout master");
        
        // Rebase changes
        if( ! empty( $this->options['merge-strategy'] ) ){
            $this->passthru("git -C {$this->git_dir} rebase -X {$this->options['merge-strategy']} $next_ref");
        } else {
            $this->passthru("git -C {$this->git_dir} rebase $next_ref");
        }

        // Confirm deployment
        $confirmation_message = 'Are you sure you want to hotfix deploy the changes from the {multidev} straight to the {env} environment on {site}?';

        $confirm = $this->confirm(
            $confirmation_message . "\n",
            [
                'multidev' => $this->multidev,
                'site' => $this->site['name'],
                'env' => $this->env['id'],
            ]
        );

        if( ! $confirm ){
            $this->deleteTempDir();
            return;
        }

        // Create a backup before pushing the rebased hotfix to master
        if( false !== $this->options['create-backup'] ){
            $this->createBackup('dev');
        }

        // Make sure the dev environment is in git mode
        $workflow = $this->envs['dev']['env_raw']->changeConnectionMode('git');
        if (is_string($workflow)) {
            $this->log()->notice($workflow);
        } else {
            while (!$workflow->checkProgress()) {
                // TODO: Add workflow progress output
            }
            $this->log()->notice($workflow->getMessage());
        }

        // Push the rebased changes up to dev/master
        $this->passthru("git -C {$this->git_dir} push origin master --force");

        // Create a backup before doing the hotfix deploy
        if( false !== $this->options['create-backup'] ){
            $this->createBackup($this->env['id']);
        }

        // Push the tag to trigger a deployment
        $this->passthru("git -C {$this->git_dir} push origin $next_ref");

        // Wait for the deployment to finish
        $this->waitForDeployment();

        // Clear cache if needed
        if( false !== $this->options['cc'] ){
            $workflow = $this->envs[$this->env['id']]['env_raw']->clearCache();
            while (!$workflow->checkProgress()) {
                // @TODO: Add Symfony progress bar to indicate that something is happening.
            }
            $this->log()->notice('Caches cleared on {site}.{env}.', ['site' => $this->site['name'], 'env' => $this->env,]);
        }

        $this->log()->notice(
            'Successfully deployed the hotfix changes from {multidev} to {env} on {site}...',
            [
                'multidev' => $this->multidev,
                'site' => $this->site['name'],
                'env' => $this->env['id'],
            ]
        );

        // Remove the temp dir
        $this->deleteTempDir();

    }

    /**
     * Wait for the latest deployment to finish
     *
     * @return void
     */
    protected function waitForDeployment()
    {
        $startTime = 0;
        $expectedWorkflowDescription = "Deploy code to \"live\"";
        $maxWaitInSeconds = 60;
        $startWaiting = time();

        while(time() - $startWaiting < $maxWaitInSeconds) {
            $workflow = $this->getLatestWorkflow($this->site['site_raw']);
            $workflowCreationTime = $workflow->get('created_at');
            $workflowDescription = $workflow->get('description');
            if (($workflowCreationTime > $startTime) && ($expectedWorkflowDescription == $workflowDescription)) {
                $this->log()->notice("Workflow '{current}' {status}.", ['current' => $workflowDescription, 'status' => $workflow->getStatus(), ]);
                if ($workflow->isSuccessful()) {
                    return;
                }
            }
            else {
                $this->log()->notice("Current workflow is '{current}'; waiting for '{expected}'", ['current' => $workflowDescription, 'expected' => $expectedWorkflowDescription]);
            }
            // Wait a bit, then spin some more
            sleep(5);
        }
    }

    /**
     * Fetch the info about the currently-executing (or most recently completed)
     * workflow operation.
     */
    protected function getLatestWorkflow($site)
    {
        $workflows = $site->getWorkflows()->fetch(['paged' => false,])->all();
        $workflow = array_shift($workflows);
        $workflow->fetchWithLogs();
        return $workflow;
    }

    /**
     * Create Backup
     *
     * @param array $env environment to backup
     * @param string $element the element to backup
     * @return object an instance of the backup element
     */
    private function createBackup($env, $element = 'all' )
    {
        $message = 'Creating a {element} backup on the {site}.{env} environment...';
        
        if( 'all' === $element ){
            $message = 'Creating a backup of the code, database and media files on the {site}.{env} environment...';
        }

        $this->log()->notice(
            $message,
            [
                'site' => $this->site['name'],
                'env' => $env,
                'element' => $element,
            ]
        );

        $backup_options = ['element' => ( $element !== 'all' ) ? $element : null, 'keep-for' => 365,];
        
        $backup = $this->envs[$env]['env_raw']->getBackups()->create($backup_options);

        while (!$backup->checkProgress()) {
            // @todo: Add Symfony progress bar to indicate that something is happening.
        }

        $message = "Finished backing up the {element} on the {site}.{env} environment.";
        
        if( 'all' === $element ){
            $message = "Finished backing up the code, database and media files on the {site}.{env} environment.";
        }
        
        $this->log()->notice(
            $message,
            [
                'site' => $this->site['name'],
                'env' => $env,
                'element' => $element,
            ]
        );

        return $backup;
    }

    private function cloneSiteLocally() {
        $this->createTempDir();
        $this->deleteGitDir();
        $this->log()->notice(
            'Cloning code for {site_env} to {git_dir}...',
            array(
                'site_env' => $this->site_env,
                'git_dir' => $this->git_dir,
            )
        );
        $this->passthru("git clone {$this->git_url} {$this->git_dir}");
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
        // Set class options
        if( ! empty($options) ){
            foreach( $options as $key => $value ){
                $this->options[$key] = $value;
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
