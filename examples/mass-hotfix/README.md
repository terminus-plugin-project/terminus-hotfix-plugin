# Terminus Hotfix Plugin Mass Update Script Example

## Functionality

This script loops through a list of sites provided by Terminus, creates a hotfix multidev, applies upstream updates, and deploys the upstream updates directly to the live environment without going through the standard dev, test, live workflow.

## Purpose

The script was written to deploy critical security updates, such as [Drupalgeddon 2](https://groups.drupal.org/security/faq-2018-002), that need to be deployed to all sites as quickly as possible, regardless of potential for breaking functional and visual changes.

Example scenario: a severe security vulnerability is available as a WordPress or Drupal [Pantheon upstream update](https://pantheon.io/docs/core-updates/). As a tech lead, I wish to deploy this update as soon as possible to all of the sites I manage, regardless of whether it breaks some functionality, as attacks are expected within hours.

## Disclaimer

The script is a proof of concept example, use at your own risk.

**Warning:** this script deploys updates to live sites without quality assurance and has the potential to cause downtime, break aesthetics/functionality, etc. It is for advanced users who are familiar with Terminus, scripting, the the nuances of the sites they manage, and who have the authority to deploy security updates without Q/A to mitigate risk.

## Requirements

* [Terminus](https://github.com/pantheon-systems/terminus) `1.8.0` or greater
* [git command line](https://git-scm.com/book/en/v2/Getting-Started-Installing-Git)
* The latest version of the [Terminus hotfix plugin](https://github.com/terminus-plugin-project/terminus-hotfix-plugin)

## Instructions

These instructions assume a unix environment.

1) Save `terminus-mass-hotfix.sh` to your desktop
1) Update the `PANTHEON_ORG_NAME` and `MULTIDEV` variables as needed
1) Update the site list command being stored in `PANTHEON_SITE_LIST` as needed
    - For example, filtering by tag
1) Authenticate with Terminus
    - Ensure you are using a machine token that has sufficient permissions to access, edit and deploy to all of the sites in the command above
1) Double check the script logic, incuding your edits
1) Run the script with `sh ~/Desktop/terminus-mass-hotfix.sh`