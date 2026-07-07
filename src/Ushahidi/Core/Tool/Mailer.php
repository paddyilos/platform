<?php

/**
 * Ushahidi Mailer
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @copyright  2014 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

namespace Ushahidi\Core\Tool;

use Illuminate\Support\Str;
use Illuminate\Contracts\Mail\Mailer as LaravelMailer;
use Ushahidi\Contracts\Mailer as MailerContract;
use Ushahidi\Modules\V5\Models\Config as SiteConfigModel;

class Mailer implements MailerContract
{
    protected $mailer;

    public function __construct(LaravelMailer $mailer)
    {
        $this->mailer = $mailer;
    }

    public function send($to, $type, array $params = null)
    {
        // Only available type right now is 'resetpassword'
        $method = 'send'.Str::ucfirst($type);
        if (method_exists($this, $method)) {
            $this->$method($to, $params);
        } else {
            // Exception
            throw new \Exception('Unsupported mail type: ' + $type);
        }
    }

    protected function sendResetpassword($to, $params)
    {
        // Read site config directly via the (reliable) V5 Eloquent model —
        // Site::getSiteConfig(), which getSite()->getName()/getEmail()/
        // getClientUri() rely on, goes through a legacy AuraDI-constructed
        // repository that silently returns stale/empty results for the
        // "site" config group in this deployment's single-tenant setup.
        $site_config = SiteConfigModel::where('group_name', 'site')
            ->whereIn('config_key', ['name', 'email', 'client_url'])
            ->pluck('config_value', 'config_key');

        $site_name = $site_config->get('name') ?: 'Deployment';
        $site_email = $site_config->get('email')
            ?: (($host = request()->getHost()) ? "noreply@$host" : null);
        $site_client_url = $site_config->get('client_url') ?: env('DEFAULT_CLIENT_URL');

        $data = [
            'client_url' => $site_client_url,
            'site_name' => $site_name,
            'site_email' => $site_email,
            'user_name' => $params['user_name'],
            'reset_string' => $params['string'],
            'reset_code' => $params['code'],
            'duration' => $params['duration']
        ];

        $subject = $site_name.': Password reset';

        $this->mailer->send(
            'emails/forgot-password',
            $data,
            function ($message) use ($to, $subject, $site_email, $site_name) {
                $message->to($to);
                $message->subject($subject);
                // Keep the deployment name as the sender display name
                // unconditionally, even if no site email is configured.
                $message->from($site_email ?: config('mail.from.address'), $site_name);
            }
        );
    }
}
