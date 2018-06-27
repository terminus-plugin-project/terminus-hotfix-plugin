#!/bin/bash

# Fail on any errors
set -ex

# Stash org name
PANTHEON_ORG_NAME=awesome-agency

# Stash hotfix multidev name
MULTIDEV=hotfix

# Stash site list
# You can filter by tags, site upstream, etc.
# For example, terminus org:site:list --tag=hotfix
PANTHEON_SITE_LIST="$(terminus org:site:list -n $PANTHEON_ORG_NAME --format=list --field=Name --tag=hotfix)"

# Loop through all sites from our list
while read -r SITE_NAME; do
    # Create a hotfix multidev based on the live environment
    terminus hotfix:env:create $SITE_NAME.live ${MULTIDEV}

    # Clear site upstream cache
    terminus site:upstream:clear-cache $SITE_NAME

    # Stash site framework
    PANTHEON_FRAMEWORK="$(terminus site:info ${SITE_NAME} --field=framework)"

    # Check for upstream updates
    HAS_UPSTREAM_UPDATES="$(terminus upstream:updates:list ${SITE_UUID}.${MULTIDEV}  --format=list  2>&1)"

    # Continue to the next site if there were not any upstream updates
    if [[ ${HAS_UPSTREAM_UPDATES} == *"no available updates"* ]]
    then
        # no upstream updates available
        echo -e "\nNo upstream updates found for the site ${SITE_NAME}..."
        continue
    fi

    # Apply upstream updates
    echo -e "\nApplying upstream updates on the hotfix multidev for ${SITE_NAME}..."
    terminus -n upstream:updates:apply ${SITE_NAME}.${MULTIDEV} --yes --updatedb --accept-upstream


    # Deploy the hotfix from the multidev directly to the live environment
    echo -e "\nDeploying upstream updates from the hotfix multidev directly to live for ${SITE_NAME}..."
    terminus -n hotfix:env:deploy ${SITE_NAME}.live ${MULTIDEV} --cc --create-backup --yes

    # Run update.php on the live environment
    if [[ ${CMS_FRAMEWORK} == "wordpress" ]]
    then
        terminus -n wp $SITE_NAME.$MULTIDEV -- core update-db
    fi
    
    if [[ ${CMS_FRAMEWORK} == "drupal" ]]
    then
        terminus -n drush $SITE_NAME.$MULTIDEV -- updatedb
    fi


done <<< "$PANTHEON_SITE_LIST"