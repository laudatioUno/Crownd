<?php

declare(strict_types=1);

namespace Crownd\Controller;

use Crownd\Http\Request;
use Crownd\Http\Response;
use Crownd\Service\Branding;
use Crownd\Validation;

final class AdminController extends Controller
{
    public function dashboard(Request $request, array $params): Response
    {
        $this->admin();
        $matches = $this->app->matches();
        $open = $matches->open();

        return $this->render('admin/dashboard.html.twig', [
            'open' => $open,
            'upcoming' => $open === null ? $matches->upcoming() : null,
            'last' => $matches->lastClosed(),
            'team_count' => count($this->app->teams()->all()),
            'vote_url' => $request->absoluteUrl('/'),
        ]);
    }

    public function settings(Request $request, array $params): Response
    {
        $this->admin();

        return $this->settingsForm($this->app->settings()->all(), []);
    }

    public function saveSettings(Request $request, array $params): Response
    {
        $this->admin();
        $input = [
            'club_name' => $request->input('club_name'),
            'primary_color' => $request->input('primary_color'),
            'award_label' => $request->input('award_label'),
            'inactive_text' => $request->input('inactive_text'),
            'thanks_text' => $request->input('thanks_text'),
            'privacy_url' => $request->input('privacy_url'),
        ];
        $errors = [];
        if ($e = Validation::required($input['club_name'])) {
            $errors['club_name'] = $e;
        }
        if ($e = Validation::required($input['award_label'], 40)) {
            $errors['award_label'] = $e;
        }
        if (Branding::validColor($input['primary_color']) === null) {
            $errors['primary_color'] = 'validation.color';
        }
        foreach (['inactive_text', 'thanks_text'] as $field) {
            if (mb_strlen($input[$field]) > 500) {
                $errors[$field] = 'validation.too_long';
            }
        }
        if ($e = Validation::optionalUrl($input['privacy_url'])) {
            $errors['privacy_url'] = $e;
        }
        $logo = $this->upload($request, 'logo', $errors);
        if ($errors !== []) {
            if (is_string($logo)) {
                $this->app->images()->delete($logo);
            }

            return $this->settingsForm($input, $errors, 422);
        }

        $settings = $this->app->settings();
        $old = $settings->get('logo_file');
        if (is_string($logo) || $request->input('remove_logo') === '1') {
            $input['logo_file'] = is_string($logo) ? $logo : null;
            $this->app->images()->delete($old);
        }
        $input['primary_color'] = (string) Branding::validColor($input['primary_color']);
        $settings->setMany($input);
        $this->flash('success', 'settings.saved');

        return $this->redirect('/admin/settings');
    }

    /** Printable poster with the QR code of the fixed voting link. */
    public function poster(Request $request, array $params): Response
    {
        $this->admin();
        $url = $request->absoluteUrl('/');

        return $this->render('admin/poster.html.twig', [
            'vote_url' => rtrim($url, '/'),
            'qr' => $this->app->qr()->dataUri(rtrim($url, '/')),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function settingsForm(array $values, array $errors, int $status = 200): Response
    {
        return $this->render('admin/settings.html.twig', [
            'values' => $values + [
                'club_name' => '', 'primary_color' => Branding::DEFAULT_COLOR, 'award_label' => 'Player of the Match',
                'inactive_text' => '', 'thanks_text' => '', 'privacy_url' => '',
            ],
            'errors' => $errors,
        ], $status);
    }
}
