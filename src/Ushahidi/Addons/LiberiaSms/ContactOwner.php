<?php

namespace Ushahidi\Addons\LiberiaSms;

use Illuminate\Support\Facades\DB;

/**
 * Liberia custom — resolves which user account (if any) owns the phone
 * number an SMS report came from, so the client can show the account
 * holder's name instead of the raw number.
 *
 * Lives in the addon (not in vendored upstream files) so it survives
 * upstream merges; upstream files only call attachUser().
 *
 * Matching compares digits only, so "+231 77 123 4567" saved in Account
 * Settings matches the "+231771234567" an SMS gateway delivers.
 */
class ContactOwner
{
    /**
     * Strip everything but digits.
     */
    public static function normalize(?string $number): string
    {
        return preg_replace('/\D+/', '', (string) $number);
    }

    /**
     * @return array{id:int,realname:?string}|null
     */
    public static function findUser(?string $number): ?array
    {
        $digits = self::normalize($number);
        if ($digits === '') {
            return null;
        }

        $expr = 'contacts.contact';
        foreach ([' ', '-', '(', ')', '.', '+'] as $char) {
            $expr = "REPLACE($expr, '$char', '')";
        }

        $user = DB::table('contacts')
            ->join('users', 'users.id', '=', 'contacts.user_id')
            ->where('contacts.type', 'phone')
            ->whereNotNull('contacts.user_id')
            ->whereRaw("$expr = ?", [$digits])
            ->orderBy('contacts.id')
            ->first(['users.id', 'users.realname']);

        return $user ? ['id' => (int) $user->id, 'realname' => $user->realname] : null;
    }

    /**
     * Add a `user` {id, realname} attribute to a contact model when its
     * phone number belongs to a user account. Call for managers only.
     */
    public static function attachUser($contact)
    {
        if ($contact && $contact->type === 'phone') {
            $user = self::findUser($contact->contact);
            if ($user) {
                $contact->setAttribute('user', $user);
            }
        }
        return $contact;
    }
}
