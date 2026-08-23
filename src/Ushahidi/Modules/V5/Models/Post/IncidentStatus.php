<?php
namespace Ushahidi\Modules\V5\Models\Post;

// Liberia PBO custom field — an admin-only workflow status, independent of
// PostStatus (published/draft/archived). See ../../../../../../LIBERIA_CUSTOM.md.
class IncidentStatus
{
    const VERIFICATION_IN_PROGRESS = 'verification_in_progress';
    const UNVERIFIED = 'unverified';
    const VERIFIED = 'verified';
    const RESPONDED = 'responded';
    const EVALUATED = 'evaluated';

    public static function all()
    {
        return [
            IncidentStatus::VERIFICATION_IN_PROGRESS,
            IncidentStatus::UNVERIFIED,
            IncidentStatus::VERIFIED,
            IncidentStatus::RESPONDED,
            IncidentStatus::EVALUATED,
        ];
    }
}
