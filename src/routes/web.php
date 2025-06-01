<?php

use Phaseolies\Support\Facades\Route;
use Doppar\Orion\Support\Facades\Process;

Route::get('orion', function () {
    // $result = Process::ping('ls -la')->execute();
    // $result = Process::ping('rm -rf /; echo "hacked"')->execute();
    // return $result->getOutput();
    // return $result->getError();

    // With output handler
    // $result = Process::ping('ls -la')
    //     ->withOutputHandler(function (string $type, string $output) {
    //         echo $output;
    //     })
    //     ->execute();

    // return $result;

    // Silent execution
    // If your process is writing a significant amount of output that you are not interested in, you can conserve memory by disabling output retrieval entirely. To accomplish this, invoke the quietly method while building the process:

    // $result = Process::pingSilently()->execute('bash importer.sh');
    // return $result;

    $result = Process::pipeline()
        ->add('cat server.php')
        ->add('grep -i "doppar"')
        ->execute();

    if ($result->wasSuccessful()) {
        //
    }
    dd($result);

    // Async process
    // $process = Process::ping('cat server.php')
    //     ->withTimeout(120)
    //     ->asAsync();

    // while ($process->isRunning()) {
    //     // You can check incremental output if needed
    //     // $output = $process->getLatestOutput();
    //     // $error = $process->getLatestError();
    // }

    // $result = $process->waitForCompletion();
    // dd($result);

    // Async with output monitoring
    // $process = Process::ping('cat server.php')
    //     ->withTimeout(120)
    //     ->asAsync();

    // while ($process->isRunning()) {
    //     echo $process->getLatestOutput();
    //     echo $process->getLatestError();
    //     sleep(1);
    // }

    // Wait for condition
    // $process = Process::ping('cat server.php')->asAsync();
    // $process->until(function (string $type, string $output) {
    //     return $output === 'Ready...';
    // });

    // Timeout verification
    // $process = Process::ping('sleep 10') // Will run for 10 seconds
    //     ->withTimeout(2)   // Set timeout to 2 seconds
    //     ->asAsync();

    // try {
    //     while ($process->isRunning()) {
    //         $process->verifyTimeout(); // Will throw if timed out
    //         // Do other work or sleep
    //         sleep(1);
    //     }

    //     $result = $process->waitForCompletion();
    //     dd($result);
    // } catch (\Exception $e) {
    //     // Handle timeout
    //     dd("Process timed out: " . $e->getMessage());
    // }

    // Process pool
    // $pool = Process::pool()
    //     ->inDirectory(__DIR__)
    //     ->add('bash import-1.sh')
    //     ->add('bash import-2.sh')
    //     ->add('bash import-3.sh')
    //     ->start();

    // while (!empty($pool->getRunningProcesses())) {
    //     // ...
    // }

    // $results = $pool->waitForAll();

    // dd($results);

    // // Concurrent execution
    // [$first, $second, $third] = Process::asConcurrently([
    //     'ls -la',
    //     'ls -la ' . database_path(),
    //     'ls -la ' . storage_path(),
    // ], __DIR__);

    // echo $first->getOutput();

    $pool = Process::pool()
        ->withConcurrency(3)
        ->inDirectory('/var/www/html/skeleton')
        ->withOutputHandler(function ($result) {
            echo "Process completed with exit code: " . $result->getExitCode() . "\n";
        });

    $pool->add('command1')
        ->add('command2')
        ->add('command3')
        ->add('command4');

    $results = $pool->start()->waitForAll();
});
