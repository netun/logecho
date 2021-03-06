<?php
/**
 * main.php - logecho-main
 * 
 * @author joyqi
 */

// run
add_workflow('run', function () use ($context) {
    if (version_compare(PHP_VERSION, '5.4.0', '<')) {
        fatal('php version must be greater than 5.4.0, you have %s', PHP_VERSION);
    }

    do_workflow('read_opt');
});

// read_opt
add_workflow('read_opt', function () use ($context) {
    global $argv;

    $opts = [
        'init'      => 'init a blog directory using example config',
        'build'     => 'build contents to _target directory',
        'sync'      => 'sync _target by using your sync config',
        'serve'     => 'start a http server to watch your site',
        'watch'     => '',
        'archive'   => '',
        'help'      => 'help documents',
        'update'    => 'update logecho to latest version',
        'import'    => 'import data from other blogging platform which is using xmlrpc'
    ];

    if (count($argv) > 0 && $argv[0] == $_SERVER['PHP_SELF']) {
        array_shift($argv);
    }

    if (count($argv) == 0) {
        $argv[] = 'help';
    }

    $help = function () use ($opts) {
        foreach ($opts as $name => $words) {
            echo "{$name}\t{$words}\n";
        }
    };

    $name = array_shift($argv);
    if (!isset($opts[$name])) {
        console('error', 'can not handle %s command, please use the following commands', $name);
        $help();
        exit(1);
    }

    if ('help' == $name) {
        $help();
    } else {
        if ($name != 'update') {
            if (count($argv) < 1) {
                fatal('a blog directory argument is required');
            }

            list ($dir) = $argv;
            if (!is_dir($dir)) {
                fatal('blog directory "%s" is not exists', $dir);
            }

            $context->dir = rtrim($dir, '/') . '/';
            $context->cmd = __DEBUG__ ? $_SERVER['_'] . ' ' . $_SERVER['PHP_SELF'] : $_SERVER['PHP_SELF'];

            if ($name != 'init') {
                do_workflow('read_config');
            }
        }

        array_unshift($argv, $name);
        call_user_func_array('do_workflow', $argv);

        console('done', $name);
    }
});

// read config
add_workflow('read_config', function () use ($context) {
    $file = $context->dir . 'config.yaml';
    if (!file_exists($file)) {
        fatal('can not find config file "%s"', $file);
    }

    $config = Spyc::YAMLLoad($file);
    if (!$config) {
        fatal('config file is not a valid yaml file');
    }

    $context->config = $config;
});

// sync directory
add_workflow('sync', function ($source = null, $target = null) use ($context) {
    if (empty($source)) {
        $source = $context->dir . '_target';
    }

    if (empty($target)) {
        if (empty($context->config['sync'])) {
            fatal('you must specify a sync directory');
        }

        $target = $context->config['sync'];
    }

    // delete all files in target
    $files = get_all_files($target);
    $dirs = [];

    foreach ($files as $file => $path) {
        $dir = dirname($path);

        // do not remove root directory
        if (!in_array($dir, $dirs) && realpath($dir) != realpath($target)) {
            $dirs[] = $dir;
        }

        // remove all files first
        if (!unlink($path)) {
            fatal('can not unlink file %s, permission denied', $path);
        }
    }

    // remove all dirs
    $dirs = array_reverse($dirs);
    foreach ($dirs as $dir) {
        if (!rmdir($dir)) {
            fatal('can not rm directory %s, permission denied', $dir);
        }
    }

    // copy all files
    $files = get_all_files($source);
    $offset = strlen($source);

    foreach ($files as $file => $path) {
        if ($file[0] == '.') {
            continue;
        }

        $original = substr($path, $offset);
        $current = $target . '/' . $original;
        $dir = dirname($current);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                fatal('can not make directory %s, permission denied', $dir);
            }
        }

        copy($path, $current);
    }
});

// build all
add_workflow('build', function () use ($context) {
    do_workflow('compile.init');
    do_workflow('compile.compile');
    do_workflow('sync', $context->dir . '_public', $context->dir . '_target/public');
});

// init
add_workflow('init', function () use ($context) {
    if (file_exists($context->dir . 'config.yaml')) {
        $confirm = readline('target dir is not empty, continue? (Y/n) ');

        if (strtolower($confirm) != 'y') {
            exit;
        }
    }

    $dir = __DIR__ . '/../sample';
    $offset = strlen($dir);

    $files = get_all_files($dir);

    foreach ($files as $file => $path) {
        if ($file[0] == '.') {
            continue;
        }

        $original = substr($path, $offset);
        $target = $context->dir . $original;
        $dir = dirname($target);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                fatal('can not make directory %s, permission denied', $dir);
            }
        }

        copy($path, $target);
    }
});

// serve
add_workflow('serve', function () use ($context) {
    $target = $context->dir . '_target';
    if (!is_dir($target)) {
        console('info', 'building target files, please wait ...');
        exec($context->cmd . ' build ' . $context->dir);
    }

    $proc = proc_open($context->cmd . ' watch ' . $context->dir, [
        0   =>  ['pipe', 'r'],
        1   =>  ['pipe', 'w'],
        2   =>  ['file', sys_get_temp_dir() . '/logecho-error.log', 'a']
    ], $pipes, getcwd());
    stream_set_blocking($pipes[0], 0);
    stream_set_blocking($pipes[1], 0);

    console('info', 'Listening on localhost:7000');
    console('info', 'Document root is %s', $target);
    console('info', 'Press Ctrl-C to quit');
    exec('/usr/bin/env php -S localhost:7000 -t ' . $target);
});

// archive
add_workflow('archive', function () use ($context) {
    // init complier
    do_workflow('compile.init');
    
    foreach ($context->config['blocks'] as $type => $block) {
        if (!isset($block['source']) || !is_string($block['source'])) {
            continue;
        }

        $source = trim($block['source'], '/');
        $files = glob($context->dir . '/' . $source . '/*.md');
        $list = [];

        foreach ($files as $file) {
            list ($metas) = do_workflow('compile.get_metas', $file);
            $date = $metas['date'];
            
            $list[$file] = $date;
        }

        asort($list);
        $index = 1;

        foreach ($list as $file => $date) {
            $info = pathinfo($file);
            $fileName = $info['filename'];
            $dir = $info['dirname'];

            if (preg_match("/^[0-9]{4}\.(.+)$/", $fileName, $matches)) {
                $fileName = $matches[1];
            }

            $source = realpath($file);
            $target = rtrim($dir, '/') . '/' . str_pad($index, 4, '0', STR_PAD_LEFT) . '.' . $fileName . '.md';

            if ($source != $target && !file_exists($target)) {
                console('info', basename($source) . ' => ' . basename($target));
                rename($source, $target);
            }

            $index ++;
        }
    }
});

// watch
add_workflow('watch', function () use ($context) {
    $lastSum = '';

    while (true) {
        // get sources
        $sources = ["\/_theme\/", "\/_public\/"];
        $sum = '';

        foreach ($context->config['blocks'] as $type => $block) {
            if (!isset($block['source']) || !is_string($block['source'])) {
                continue;
            }

            $source = trim($block['source'], '/');
            $source = empty($source) ? '/' : '/' . $source . '/';

            $sources[] = preg_quote($source, '/');
        }

        if (!empty($sources)) {
            $regex = "/^" . preg_quote(rtrim($context->dir, '/'))
                . "(" . implode('|', $sources) . ")/";

            $files = get_all_files($context->dir);

            foreach ($files as $file => $path) {
                if (!preg_match($regex, $path) || $file[0] == '.') {
                    continue;
                }

                $sum .= md5_file($path);
            }

            $sum = md5($sum . md5_file($context->dir . 'config.yaml'));
            if ($lastSum != $sum) {
                exec($context->cmd . ' build ' . $context->dir);
                $lastSum = $sum;
            }
        }

        sleep(1);
    }
});

// import
add_workflow('import', function () use ($context) {
    do_workflow('import.init');
});
