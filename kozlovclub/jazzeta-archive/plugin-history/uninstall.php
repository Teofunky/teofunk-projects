<?php
/**
 * Removing the plugin deliberately deletes nothing: issues, articles, history, numbering,
 * settings and roles stay in the database, exactly as with 0.3.x. Reinstalling any version
 * of the newspaper picks them up again. Deleting data is a separate, manual decision.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
