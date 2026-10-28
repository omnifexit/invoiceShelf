<?php

namespace App\Policies;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Silber\Bouncer\BouncerFacade;

/**
 * Credit notes are an invoice action, so they reuse the invoice abilities.
 */
class CreditNotePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any credit notes.
     */
    public function viewAny(User $user): bool
    {
        if (BouncerFacade::can('view-invoice', Invoice::class)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view the credit note.
     */
    public function view(User $user, CreditNote $creditNote): bool
    {
        if (BouncerFacade::can('view-invoice', Invoice::class) && $user->hasCompany($creditNote->company_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create credit notes.
     */
    public function create(User $user): bool
    {
        if (BouncerFacade::can('create-invoice', Invoice::class)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the credit note.
     */
    public function delete(User $user, CreditNote $creditNote): bool
    {
        if (BouncerFacade::can('delete-invoice', Invoice::class) && $user->hasCompany($creditNote->company_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete multiple credit notes.
     */
    public function deleteMultiple(User $user): bool
    {
        if (BouncerFacade::can('delete-invoice', Invoice::class)) {
            return true;
        }

        return false;
    }
}
