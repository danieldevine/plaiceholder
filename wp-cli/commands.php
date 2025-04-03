<?php

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('plaiceholder create', \Plaiceholder\Commands\CreatePlaceholder::class);
    WP_CLI::add_command('plaiceholder cleanup', [new \Plaiceholder\Commands\CreatePlaceholder(), 'cleanup']);
}
