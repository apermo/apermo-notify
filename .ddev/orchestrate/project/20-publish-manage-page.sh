#!/usr/bin/env bash

# Create + assign the apermo-notify Subscription Management page so the
# plugin's "Manage your subscriptions" links resolve out of the box.
# Without this, the admin notice nags on every screen until an admin
# picks a page manually.
#
# Idempotent:
#   - If the apermo_notify_settings option already points at a published
#     page, do nothing.
#   - Else, look up an existing page with post_name='manage-subscriptions'
#     and assign / publish it if found.
#   - Else, create a new published page and write its ID into
#     apermo_notify_settings.manage_page_id.

set -euo pipefail

if [ -z "${WP_PATH:-}" ]; then
    echo "WP_PATH not set, skipping manage-subscriptions bootstrap."
    return 0
fi

publish_for_site() {
    local site_args=("$@")

    local settings_json
    settings_json="$(wp option get apermo_notify_settings --format=json "${site_args[@]}" --path="${WP_PATH}" 2>/dev/null || echo '{}')"

    local current_id
    current_id="$(echo "$settings_json" | wp eval 'echo (int) ( json_decode( file_get_contents("php://stdin"), true )["manage_page_id"] ?? 0 );' --path="${WP_PATH}" 2>/dev/null || echo 0)"
    current_id="${current_id//[^0-9]/}"
    : "${current_id:=0}"

    if [ "$current_id" -gt 0 ]; then
        local status
        status="$(wp post get "$current_id" --field=post_status "${site_args[@]}" --path="${WP_PATH}" 2>/dev/null || true)"
        if [ "$status" = "publish" ]; then
            return 0
        fi
        wp post update "$current_id" --post_status=publish "${site_args[@]}" --path="${WP_PATH}" >/dev/null
        echo "Published existing apermo-notify manage page (ID ${current_id})."
        return 0
    fi

    local existing_id
    existing_id="$(wp post list --post_type=page --name=manage-subscriptions --post_status=any --field=ID "${site_args[@]}" --path="${WP_PATH}" 2>/dev/null | head -n 1)"
    existing_id="${existing_id//[^0-9]/}"
    : "${existing_id:=0}"

    # Pick an author. Prefer the first administrator so the page shows
    # a proper byline in the Pages list; fall back to user 1.
    local author_id
    author_id="$(wp user list --role=administrator --field=ID --number=1 "${site_args[@]}" --path="${WP_PATH}" 2>/dev/null | head -n 1)"
    author_id="${author_id//[^0-9]/}"
    : "${author_id:=1}"

    local page_id="$existing_id"
    if [ "$page_id" -gt 0 ]; then
        wp post update "$page_id" --post_status=publish --post_author="$author_id" "${site_args[@]}" --path="${WP_PATH}" >/dev/null
        echo "Re-used existing manage-subscriptions page (ID ${page_id})."
    else
        page_id="$(wp post create \
            --post_type=page \
            --post_title='Manage your subscriptions' \
            --post_name='manage-subscriptions' \
            --post_status=publish \
            --post_author="$author_id" \
            --post_content='<!-- wp:paragraph --><p>Visit this page via the link in any of your notification emails to manage your subscriptions.</p><!-- /wp:paragraph -->' \
            --porcelain \
            "${site_args[@]}" --path="${WP_PATH}")"
        echo "Created manage-subscriptions page (ID ${page_id})."
    fi

    # Patch apermo_notify_settings.manage_page_id without clobbering the
    # other keys.
    wp eval "
        \$opt = get_option('apermo_notify_settings', []);
        if ( ! is_array(\$opt) ) { \$opt = []; }
        \$opt['manage_page_id'] = ${page_id};
        update_option('apermo_notify_settings', \$opt, false);
    " "${site_args[@]}" --path="${WP_PATH}" >/dev/null
    echo "Assigned manage-subscriptions page (ID ${page_id}) to apermo_notify_settings.manage_page_id."
}

if [ "${WP_MULTISITE:-0}" = "1" ]; then
    site_urls="$(wp site list --field=url --path="${WP_PATH}" 2>/dev/null || true)"
    if [ -z "$site_urls" ]; then
        publish_for_site
    else
        while IFS= read -r site_url; do
            [ -z "$site_url" ] && continue
            publish_for_site --url="$site_url"
        done <<< "$site_urls"
    fi
else
    publish_for_site
fi
