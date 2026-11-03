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
            'sponsor_name' => $request->input('sponsor_name'),
            'sponsor_text' => $request->input('sponsor_text'),
            'sponsor_places' => implode(',', array_values(array_intersect(Branding::SPONSOR_PLACES, self::stringList($request, 'sponsor_places')))),
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
        if (mb_strlen($input['sponsor_name']) > 100) {
            $errors['sponsor_name'] = 'validation.too_long';
        }
        if (mb_strlen($input['sponsor_text']) > 80) {
            $errors['sponsor_text'] = 'validation.too_long';
        }
        $settings = $this->app->settings();
        $logo = $this->upload($request, 'logo', $errors);
        $sponsorLogo = $this->upload($request, 'sponsor_logo', $errors, 1000);
        $removeSponsor = $request->input('remove_sponsor_logo') === '1';
        $hasSponsor = is_string($sponsorLogo) || ($settings->get('sponsor_logo') !== '' && !$removeSponsor);
        if ($hasSponsor && $input['sponsor_name'] === '' && !isset($errors['sponsor_logo'])) {
            $errors['sponsor_name'] = 'settings.sponsor_name_required';
        }
        if ($errors !== []) {
            foreach ([$logo, $sponsorLogo] as $file) {
                if (is_string($file)) {
                    $this->app->images()->delete($file);
                }
            }

            return $this->settingsForm($input + $settings->all(), $errors, 422);
        }

        $old = $settings->get('logo_file');
        if (is_string($logo) || $request->input('remove_logo') === '1') {
            $input['logo_file'] = is_string($logo) ? $logo : null;
            $this->app->images()->delete($old);
        }
        $oldSponsor = $settings->get('sponsor_logo');
        if (is_string($sponsorLogo) || $removeSponsor) {
            $input['sponsor_logo'] = is_string($sponsorLogo) ? $sponsorLogo : null;
            $this->app->images()->delete($oldSponsor);
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

    /** @return list<string> */
    private static function stringList(Request $request, string $key): array
    {
        $raw = $request->post[$key] ?? [];

        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
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
                'sponsor_name' => '', 'sponsor_text' => '', 'sponsor_logo' => '',
                'sponsor_places' => implode(',', Branding::SPONSOR_DEFAULT_PLACES),
            ],
            'sponsor_places' => Branding::SPONSOR_PLACES,
            'errors' => $errors,
        ], $status);
    }
}
