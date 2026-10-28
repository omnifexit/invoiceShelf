<?php

/*
|--------------------------------------------------------------------------
| Architecture rules
|--------------------------------------------------------------------------
|
| The structural conventions from CLAUDE.md, enforced. Files listed in
| ignoring() broke a rule before these tests existed; new code may not.
|
*/

arch('no debugging calls are left behind')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('env() is only read in config files')
    ->expect('env')
    ->not->toBeUsed()
    ->ignoring([
        'App\\Space\\ModuleInstaller',
        'App\\Jobs\\CreateBackupJob',
        'App\\Models\\FileDisk',
        'App\\Http\\Middleware\\TrustProxies',
    ]);

arch('controllers extend the base controller')
    ->expect('App\\Http\\Controllers')
    ->classes()
    ->toExtend('App\\Http\\Controllers\\Controller')
    ->ignoring('App\\Http\\Controllers\\Controller');

arch('controllers do not query the database directly')
    ->expect('App\\Http\\Controllers')
    ->not->toUse('Illuminate\\Support\\Facades\\DB')
    ->ignoring('App\\Http\\Controllers\\V1\\Admin\\Customer\\CustomersController');

arch('form requests extend FormRequest')
    ->expect('App\\Http\\Requests')
    ->classes()
    ->toExtend('Illuminate\\Foundation\\Http\\FormRequest');

arch('API resources extend JsonResource')
    ->expect('App\\Http\\Resources')
    ->classes()
    ->toExtend('Illuminate\\Http\\Resources\\Json\\JsonResource');

arch('models extend Eloquent')
    ->expect('App\\Models')
    ->classes()
    ->toExtend('Illuminate\\Database\\Eloquent\\Model');

arch('policies are named after what they guard')
    ->expect('App\\Policies')
    ->classes()
    ->toHaveSuffix('Policy');

arch('mails extend Mailable')
    ->expect('App\\Mail')
    ->classes()
    ->toExtend('Illuminate\\Mail\\Mailable');

arch('jobs are queueable')
    ->expect('App\\Jobs')
    ->classes()
    ->toImplement('Illuminate\\Contracts\\Queue\\ShouldQueue');

arch('console commands extend Command')
    ->expect('App\\Console\\Commands')
    ->classes()
    ->toExtend('Illuminate\\Console\\Command');
