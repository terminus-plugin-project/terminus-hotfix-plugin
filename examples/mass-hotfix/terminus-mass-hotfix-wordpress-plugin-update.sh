#!/bin/bash

# Fail on any errors
set +ex

# Stash org name
PANTHEON_ORG_NAME=ataylor

# Stash hotfix multidev name
MULTIDEV=hotfix

# Stash site list
# You can filter by tags, site plugin, etc.
# For example, terminus org:site:list --tag=hotfix
PANTHEON_SITE_LIST="$(terminus org:site:list -n $PANTHEON_ORG_NAME --format=string --field=Name --tag=hotfix)"

# Stash plugins to update
PLUGINS_TO_UPDATE='akismet solr-power'

echo -e "\nAttempting to update the plugins ${PLUGINS_TO_UPDATE} on the sites:\n${PANTHEON_SITE_LIST}"

# Loop through all sites from our list
while read SITE_NAME; do
    echo -e "\nAttempting to update the plugins ${PLUGINS_TO_UPDATE} on ${SITE_NAME}..."

    # Set updates applied to false
    UPDATES_APPLIED=false

    # Set multidev created to false
    MULTIDEV_CREATED=false

    # Loop through all plugins to update from our list
    for CURRENT_PLUGIN in ${PLUGINS_TO_UPDATE}; do
        # Check if the current plugin has an update available on live, where we will hotfix from
        PLUGIN_HAS_UPDATE="$(terminus -n wp ${SITE_NAME}.live -- plugin list --update=available --name=${CURRENT_PLUGIN} --format=count)"
        
        # Continue to the next plugin if there is not an update available for this one
        if [ "$PLUGIN_HAS_UPDATE" == "0" ]
        then
            echo -e "\nNo plugin update found for ${CURRENT_PLUGIN} on for ${SITE_NAME}"
            continue
        fi

        echo -e "\nPlugin update found for ${CURRENT_PLUGIN} on the live environment of ${SITE_NAME}"

        # Create the multidev if needed
        if [ "${MULTIDEV_CREATED}" == false ]
        then
            # Create a hotfix multidev based on the live environment
            terminus hotfix:env:create $SITE_NAME.live ${MULTIDEV}

            # Put the multidev in SFTP mode
            echo -e "\nEnabling SFTP mode on the ${MULTIDEV} multidev for ${SITE_NAME}..."
            terminus -n connection:set $SITE_NAME.$MULTIDEV sftp

            # Set multidev created to true
            MULTIDEV_CREATED=true
        fi

        # Apply current plugin update
        echo -e "\nUpdating the ${CURRENT_PLUGIN} on the ${MULTIDEV} multidev for ${SITE_NAME}..."
        terminus -n wp $SITE_NAME.$MULTIDEV -- plugin update ${CURRENT_PLUGIN}

        # Wait 5 seconds to let files sync
        sleep 5

        # Run env:diffstat to make Pantheon detect the changes
        terminus -n env:diffstat $SITE_NAME.$MULTIDEV --field=file 2>&1

        # Commit the current plugin updates
        echo -e "\nCommitting the ${CURRENT_PLUGIN} updates on the ${MULTIDEV} multidev for ${SITE_NAME}..."
        terminus -n env:commit $SITE_NAME.$MULTIDEV --message="Updated the ${CURRENT_PLUGIN} plugin"

        # Set updates applied to true
        UPDATES_APPLIED=true
    done

    # Continue to the next site if there were not any plugin updates available
    if [ "${UPDATES_APPLIED}" == false ]
    then
        # No plugin updates available
        echo -e "\nNo available plugin updates found for the ${PLUGINS_TO_UPDATE} plugins on the site ${SITE_NAME}..."

        # Delete the hotfix multidev if needed
        if [ "${MULTIDEV_CREATED}" == true ]
        then
            echo -e "\nDeleting the ${MULTIDEV} multidev environment for site ${SITE_NAME}..."
            terminus -n multidev:delete $SITE_NAME.$MULTIDEV --delete-branch --yes || true
        fi

    # Otherwise proceed with deploying the hotfix updates
    else
        # Deploy the hotfix from the multidev directly to the live environment
        echo -e "\nDeploying plugin updates from the ${MULTIDEV} multidev directly to live for ${SITE_NAME}..."
        terminus -n hotfix:env:deploy ${SITE_NAME}.live ${MULTIDEV} --cc --create-backup --yes

        # Run update.php on the live environment
        terminus -n wp $SITE_NAME.$MULTIDEV -- core update-db

        # Delete the hotfix multidev if needed
        if [ "${MULTIDEV_CREATED}" == true ]
        then
            echo -e "\nDeleting the ${MULTIDEV} multidev environment for site ${SITE_NAME}..."
            terminus -n multidev:delete $SITE_NAME.$MULTIDEV --delete-branch --yes || true
        fi
    fi

done <<< "${PANTHEON_SITE_LIST}"