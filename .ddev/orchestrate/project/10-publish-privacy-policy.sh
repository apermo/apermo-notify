#!/usr/bin/env bash

# Publish a Privacy Policy page so the apermo-notify consent checkbox can
# link to it. The plugin is GDPR-by-design and renders a site-wide admin
# error notice until wp_page_for_privacy_policy points to a published page,
# which is annoying on every fresh DDEV install.
#
# Idempotent:
#   - If wp_page_for_privacy_policy already points at a published page, do
#     nothing.
#   - Else, look up the page WP auto-created on install (post_name =
#     'privacy-policy'); publish it if found.
#   - Else, create a new published page and assign the option.

set -euo pipefail

if [ -z "${WP_PATH:-}" ]; then
    echo "WP_PATH not set, skipping privacy policy bootstrap."
    return 0
fi

publish_for_site() {
    local site_args=("$@")

    local current_id
    current_id="$(wp option get wp_page_for_privacy_policy "${site_args[@]}" --path="${WP_PATH}" 2>/dev/null || echo 0)"
    current_id="${current_id//[^0-9]/}"
    : "${current_id:=0}"

    if [ "$current_id" -gt 0 ]; then
        local status
        status="$(wp post get "$current_id" --field=post_status "${site_args[@]}" --path="${WP_PATH}" 2>/dev/null || true)"
        if [ "$status" = "publish" ]; then
            return 0
        fi
        wp post update "$current_id" --post_status=publish "${site_args[@]}" --path="${WP_PATH}" >/dev/null
        echo "Published existing Privacy Policy page (ID ${current_id})."
        return 0
    fi

    local draft_id
    draft_id="$(wp post list --post_type=page --name=privacy-policy --post_status=any --field=ID "${site_args[@]}" --path="${WP_PATH}" 2>/dev/null | head -n 1)"
    draft_id="${draft_id//[^0-9]/}"
    : "${draft_id:=0}"

    if [ "$draft_id" -gt 0 ]; then
        wp post update "$draft_id" --post_status=publish "${site_args[@]}" --path="${WP_PATH}" >/dev/null
        wp option update wp_page_for_privacy_policy "$draft_id" "${site_args[@]}" --path="${WP_PATH}" >/dev/null
        echo "Published WP's auto-created Privacy Policy page (ID ${draft_id}) and set wp_page_for_privacy_policy."
        return 0
    fi

    local new_id
    new_id="$(wp post create \
        --post_type=page \
        --post_title='Privacy Policy' \
        --post_name='privacy-policy' \
        --post_status=publish \
        --post_content='<!-- wp:paragraph --><p>Placeholder privacy policy for local development. Replace before going to production.</p><!-- /wp:paragraph -->' \
        --porcelain \
        "${site_args[@]}" --path="${WP_PATH}")"
    wp option update wp_page_for_privacy_policy "$new_id" "${site_args[@]}" --path="${WP_PATH}" >/dev/null
    echo "Created Privacy Policy page (ID ${new_id}) and set wp_page_for_privacy_policy."
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
