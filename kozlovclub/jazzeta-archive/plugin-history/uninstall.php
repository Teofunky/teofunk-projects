<?php
/** Uninstall is deliberately non-destructive: editions and audit evidence are retained. */
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
// No DROP, no DELETE and no mutation of site content. See docs/OPERATIONS.md for retention.
