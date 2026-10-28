<?php

namespace App\Policies;

use App\Models\BankTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Silber\Bouncer\BouncerFacade;

class BankTransactionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the imported bank transactions.
     */
    public function viewAny(User $user): bool
    {
        if (BouncerFacade::can('view-payment', Payment::class)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can link the transaction to invoices.
     */
    public function link(User $user, BankTransaction $bankTransaction): bool
    {
        if (BouncerFacade::can('create-payment', Payment::class) && $user->hasCompany($bankTransaction->company_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can ignore the transaction.
     */
    public function ignore(User $user, BankTransaction $bankTransaction): bool
    {
        if (BouncerFacade::can('create-payment', Payment::class) && $user->hasCompany($bankTransaction->company_id)) {
            return true;
        }

        return false;
    }
}
