<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceApproval
{
    public static function trialDays(): int
    {
        return max(1, (int) config('makerly.trial_days', 3));
    }

    /**
     * @return list<string>
     */
    public static function phones(): array
    {
        $phones = config('makerly.approval_phones', []);

        return array_values(array_filter(
            is_array($phones) ? $phones : [],
            fn (mixed $phone): bool => is_string($phone) && $phone !== '',
        ));
    }

    public static function email(): string
    {
        return (string) config('makerly.approval_email', '');
    }

    public static function trialMessage(Tenant $tenant): string
    {
        $ends = self::endsAt($tenant);
        $phones = implode(' or ', self::phones());
        $phonesSw = implode(' au ', self::phones());
        $email = self::email();

        return 'You can use '.$tenant->name.' until '.$ends.'. After that, an admin must approve this workspace. Call '.$phones.', or email '.$email.'.'
            ."\n\n"
            .'Unaweza kutumia '.$tenant->name.' hadi '.$ends.'. Baada ya hapo, msimamizi lazima aidhinishe nafasi hii ya kazi. Piga '.$phonesSw.', au tuma barua pepe '.$email.'.';
    }

    public static function expiredMessage(string $name): string
    {
        $phones = implode(' or ', self::phones());
        $phonesSw = implode(' au ', self::phones());
        $email = self::email();

        return 'Your 3 days are over. To keep using '.$name.', ask an admin to approve this workspace. Call '.$phones.', or email '.$email.'.'
            ."\n\n"
            .'Siku 3 zimeisha. Ili kuendelea kutumia '.$name.', omba msimamizi aidhinishe nafasi hii ya kazi. Piga '.$phonesSw.', au tuma barua pepe '.$email.'.';
    }

    public static function signupAlert(Tenant $tenant): string
    {
        return $tenant->name.' ('.$tenant->slug.') signed up. They can use the system until '.self::endsAt($tenant).'. Approve them in the platform console.';
    }

    public static function approvedMessage(string $name): string
    {
        return $name.' is approved. You can keep using the system.';
    }

    public static function memberMessage(string $name): string
    {
        return 'An admin added you to '.$name.'. Sign in with this email and the password they gave you.';
    }

    public static function deny(Request $request, Tenant $tenant, Workspace $workspace): ?Response
    {
        if ($tenant->trialHasExpired()) {
            return Inertia::render('Errors/ApprovalRequired', [
                'tenant' => $workspace->display_name ?: $tenant->name,
                'reason' => self::expiredMessage($tenant->name),
                'phones' => self::phones(),
                'email' => self::email(),
            ])->toResponse($request)->setStatusCode(403);
        }

        if ($tenant->isSuspended() || $workspace->status !== 'active') {
            return Inertia::render('Errors/Suspended', [
                'tenant' => $workspace->display_name ?: $tenant->name,
                'reason' => $tenant->suspended_reason ?: 'This workspace is suspended.',
            ])->toResponse($request)->setStatusCode(403);
        }

        return null;
    }

    private static function endsAt(Tenant $tenant): string
    {
        return $tenant->trial_ends_at
            ?->timezone((string) config('makerly.timezone', 'Africa/Dar_es_Salaam'))
            ->format('j M Y, H:i') ?? '';
    }
}
