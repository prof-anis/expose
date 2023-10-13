<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'contrib/rsync.php';

// Config

set('repository', 'https://github.com/prof-anis/expose.git');
set('application', 'Expose');
set('ssh_multiplexing', true); // Speeds up deployments
set('http_user', 'ubuntu');

set('rsync_src', function () {
    return __DIR__; // If your project isn't in the root, you'll need to change this.
});

add('rsync', [
    'exclude' => [
        '.git',
        '/.env',
        '/storage/',
        '/config-mailcoach-app/',
        '/vendor/',
        '/node_modules/',
        '.github',
        'deploy.php',
    ],
]);

// Set up a deployer task to copy secrets to the server.
// Since our secrets are stored in Gitlab, we can access them as env vars.
task('deploy:secrets', function (): void {
    file_put_contents(__DIR__.'/.env', getenv('DOT_ENV'));
    upload('.env', get('deploy_path').'/shared');
});

task('composer:update', function (): void {
    $output = run('sudo composer self-update --2');
    writeln($output);
});

after('deploy:failed', 'deploy:unlock');

set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader --no-suggest --ignore-platform-reqs');

add('shared_files', []);
add('shared_dirs', []);
add('writable_dirs', []);

// Hosts
host('tobexkee.com')
    ->setHostname('18.223.113.114')
    ->setRemoteUser('ubuntu')
    ->setDeployPath('/home/ubuntu/expose')
    ->set('labels', ['stage' => 'production']);

// Hooks

/**
 * Main deploy task.
 */
desc('Deploys your project');
task('deploy', [
    'deploy:info',
    'deploy:lock',
    'deploy:release',
    'rsync',
    'deploy:secrets',
    'deploy:shared',
    'deploy:vendors',
    'deploy:writable',
    'artisan:storage:link',
    'artisan:view:cache',
    'artisan:config:cache',
    'artisan:optimize',
    'artisan:migrate',
    'artisan:queue:restart',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:cleanup',
]);

after('deploy:failed', 'deploy:unlock');
