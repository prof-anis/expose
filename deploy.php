<?php

namespace Deployer;

// Include the Laravel & rsync recipes
require 'recipe/laravel.php';
require 'contrib/rsync.php';

define("SOURCE_ROOT", dirname(__DIR__, 2));
define('DEPLOY_DOMAINS', ['tobexkee.com' => 'tobexkee.com']);
define('SUPERVISOR_CONFIGS', ['expose.conf',]);

set('application', 'Expose');
set('ssh_multiplexing', true); // Speed up deployment

set('rsync_src', function () {
    return SOURCE_ROOT; // If your project isn't in the root, you'll need to change this.
});

add('shared_dirs', [
    'config/.expose',
]);

add('writable_dirs', [
    'config/.expose',
]);

// Configuring the rsync exclusions.
// You'll want to exclude anything that you don't want on the production server.
add('rsync', [
    'exclude' => [
        '.git',
        '/.env',
        '/vendor/',
        '/node_modules/',
        '.github',
        'deploy.php',
    ],
]);

host('tobexkee.com') // Name of the server
->setHostname('18.223.113.114') // Hostname or IP address
->setRemoteUser('ubuntu') // SSH user
//->stage('production') // Deployment stage (production, staging, etc)
->setDeployPath('/home/ubuntu/expose');

set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --optimize-autoloader --ignore-platform-req=php');

after('deploy:failed', 'deploy:unlock'); // Unlock after failed deploy

task('composer:update', function (): void {
    $output = run('sudo composer self-update --2');
    writeln($output);
});

desc('Deploy the application');

// Set up a deployer task to copy secrets to the server.
// Grabs the dotenv file from the github secret
task('deploy:secrets', function () {
    file_put_contents(SOURCE_ROOT . '/.env', getenv('DOT_ENV'));
    upload(SOURCE_ROOT . '/.env', get('deploy_path') . '/shared');
});

task('deploy', [
    'deploy:info',
    'deploy:lock',
    'deploy:release',
    'rsync',
    'deploy:secrets',
    'deploy:shared',
    'deploy:vendors',
    'deploy:writable',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:cleanup',
]);



$deployNginxFiles = function (): void {
    foreach (DEPLOY_DOMAINS as $localDomain => $DOMAIN) {
        $linkPath = "/etc/nginx/sites-enabled/$DOMAIN";
        $filePath = "/etc/nginx/sites-available/$DOMAIN";
        $command = "
            sudo cp infrastructure/production/nginx/$localDomain.conf $filePath
            if [ ! -f \"$linkPath\" ]; then
                sudo ln -s $filePath $linkPath
            fi
        ";

        run($command);
    }
};

$deploySupervisorFiles = function (string $stage): void {
    foreach (SUPERVISOR_CONFIGS as $fileName) {
        $configPath = "infrastructure/$stage/supervisor/$fileName";

        if (! file_exists($configPath)) {
            continue;
        }

        $supervisorPath = "/etc/supervisor/conf.d/$fileName";
        $content = file_get_contents($configPath);
        $command = "echo '$content' > $configPath && sudo cp $configPath $supervisorPath";

        run($command);
    }
};

$reloadNginx = function (): void {
    run('sudo nginx -t && sudo service nginx reload');
};


$completeRelease = function () use ($deploySupervisorFiles, $deployNginxFiles, $reloadNginx): void {
    within('{{release_path}}', function () use ($deploySupervisorFiles, $reloadNginx, $deployNginxFiles): void {
        run("php expose --version");

        $deploySupervisorFiles('production');
        $deployNginxFiles();
        $reloadNginx();
    });

    run('sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl restart expose:*');
};

task('deploy:done:production', $completeRelease);
after('deploy', 'deploy:done:production');
