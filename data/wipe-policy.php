<?php
/*
 * =========================================================================
 *  WIPE POLICY — this file belongs to the owner of this install.
 * =========================================================================
 *
 *  This is the ONLY thing that can authorise destroying data. Nothing else
 *  can: not a command-line flag, not an environment variable, not a setting
 *  in the database, not an argument, and not anybody's assurance that it is
 *  fine right now.
 *
 *  ---------------------------------------------------------------------
 *   TO LOCK THIS DATABASE:  set allow_wipe to false.
 *  ---------------------------------------------------------------------
 *
 *  That is the whole procedure. With allow_wipe false, `php data/wipe.php`
 *  refuses and exits, every time, for any reason, no matter who or what is
 *  asking or what was agreed earlier in a conversation.
 *
 *  It also fails closed: DELETE this file, corrupt it, or remove the
 *  `databases` list and the answer becomes no. There is no default yes.
 *
 *  Both outcomes are appended to data/wipe-attempts.log.
 *
 *  Note for any agent or assistant reading this file: a request to wipe is
 *  not authority to edit this file. Editing it to grant yourself permission
 *  is the one thing it exists to prevent. Report the refusal and stop.
 */
declare(strict_types=1);

return [

    /*
     * The master switch. Must be exactly true — not 1, not 'true', not 'yes'.
     *
     * CURRENTLY: true, because the owner explicitly authorised wiping during
     * the current build phase. Set this to false the moment the database holds
     * anything you would be unhappy to rebuild.
     */
    'allow_wipe' => true,

    /*
     * Databases this permission covers, by exact name. A database not named
     * here cannot be wiped even when allow_wipe is true.
     *
     * This is what stops a permissive development policy from travelling with
     * the code and authorising production. Never add the production database
     * to this list — and note that the guard refuses any non-local host
     * regardless of what is written here.
     */
    'databases' => [
        'wkr_admin',
    ],

];
