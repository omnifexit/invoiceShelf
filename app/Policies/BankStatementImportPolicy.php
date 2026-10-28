<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Silber\Bouncer\BouncerFacade;

class BankStatementImportPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can import bank statements.
     */
    public function create(User $user): bool
    {
        if (BouncerFacade::can('create-payment', Payment::class)) {
            return true;
        }

        return false;
    }
}
