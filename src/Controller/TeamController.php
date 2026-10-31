<?php

declare(strict_types=1);

namespace Crownd\Controller;

use Crownd\Http\Request;
use Crownd\Http\Response;
use Crownd\Repository\PlayerRepository;
use Crownd\Service\Branding;
use Crownd\Service\ImageUpload;
use Crownd\Validation;

/**
 * Teams (own club and opponents) with logo, their player pool and squad templates.
 */
final class TeamController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $this->admin();

        return $this->render('admin/teams/index.html.twig', [
            'teams' => $this->app->teams()->all(true),
        ]);
    }

    public function create(Request $request, array $params): Response
    {
        $this->admin();

        return $this->render('admin/teams/form.html.twig', ['team' => null, 'values' => ['name' => '', 'short_name' => '', 'color' => ''], 'errors' => []]);
    }

    public function store(Request $request, array $params): Response
    {
        $this->admin();
        [$values, $errors] = $this->teamInput($request);
        $logo = $this->upload($request, 'logo', $errors);
        if ($errors !== []) {
            if (is_string($logo)) {
                $this->app->images()->delete($logo);
            }

            return $this->render('admin/teams/form.html.twig', ['team' => null, 'values' => $values, 'errors' => $errors], 422);
        }
        $id = $this->app->teams()->create(self::teamData($values) + ['logo_file' => is_string($logo) ? $logo : null]);
        $this->flash('success', 'teams.created', ['name' => $values['name']]);

        return $this->redirect('/admin/teams/' . $id);
    }

    public function show(Request $request, array $params): Response
    {
        $this->admin();
        $team = self::found($this->app->teams()->find(self::id($params)));

        return $this->teamPage($team, $team, [], []);
    }

    public function update(Request $request, array $params): Response
    {
        $this->admin();
        $team = self::found($this->app->teams()->find(self::id($params)));
        [$values, $errors] = $this->teamInput($request);
        $logo = $this->upload($request, 'logo', $errors);
        if ($errors !== []) {
            if (is_string($logo)) {
                $this->app->images()->delete($logo);
            }

            return $this->teamPage($team, $values, $errors, [], 422);
        }
        $data = self::teamData($values);
        if (is_string($logo) || $request->input('remove_logo') === '1') {
            $data['logo_file'] = is_string($logo) ? $logo : null;
            $this->app->images()->delete($team['logo_file']);
        }
        $this->app->teams()->update((int) $team['id'], $data);
        $this->flash('success', 'teams.saved');

        return $this->redirect('/admin/teams/' . $team['id']);
    }

    public function archive(Request $request, array $params): Response
    {
        $this->admin();
        $team = self::found($this->app->teams()->find(self::id($params)));
        $archived = (int) $team['archived'] !== 1;
        $this->app->teams()->update((int) $team['id'], ['archived' => $archived ? 1 : 0]);
        $this->flash('success', $archived ? 'teams.archived' : 'teams.restored', ['name' => (string) $team['name']]);

        return $this->redirect('/admin/teams');
    }

    public function delete(Request $request, array $params): Response
    {
        $this->admin();
        $team = self::found($this->app->teams()->find(self::id($params)));
        if ($this->app->teams()->isUsed((int) $team['id'])) {
            $this->flash('error', 'teams.delete_used');

            return $this->redirect('/admin/teams/' . $team['id']);
        }
        foreach ($this->app->players()->forTeam((int) $team['id'], true) as $player) {
            $this->deletePhotoFiles($player);
        }
        $this->app->images()->delete($team['logo_file']);
        $this->app->teams()->delete((int) $team['id']);
        $this->flash('success', 'teams.deleted', ['name' => (string) $team['name']]);

        return $this->redirect('/admin/teams');
    }

    public function addPlayer(Request $request, array $params): Response
    {
        $this->admin();
        $team = self::found($this->app->teams()->find(self::id($params)));
        [$values, $errors] = $this->playerInput($request);
        $photo = $this->uploadPhoto($request, $errors);
        if ($errors !== []) {
            $this->deletePhotoFiles($photo ?? []);

            return $this->teamPage($team, $team, [], ['values' => $values, 'errors' => $errors], 422);
        }
        $id = $this->app->players()->create(self::playerData($values) + ['team_id' => (int) $team['id']] + ($photo ?? []));
        $this->addToSquads($request, (int) $team['id'], [$id]);
        $this->flash('success', 'players.created', ['name' => $values['name']]);

        return $this->redirect('/admin/teams/' . $team['id'] . '#players');
    }

    public function addPlayers(Request $request, array $params): Response
    {
        $this->admin();
        $team = self::found($this->app->teams()->find(self::id($params)));
        $list = PlayerRepository::parseList($request->input('list'));
        $ids = [];
        foreach (array_slice($list, 0, 80) as $player) {
            $ids[] = $this->app->players()->create($player + ['team_id' => (int) $team['id']]);
        }
        $this->addToSquads($request, (int) $team['id'], $ids);
        $this->flash($ids === [] ? 'warning' : 'success', $ids === [] ? 'players.bulk_empty' : 'players.bulk_created', ['count' => count($ids)]);

        return $this->redirect('/admin/teams/' . $team['id'] . '#players');
    }

    public function editPlayer(Request $request, array $params): Response
    {
        $this->admin();
        $player = self::found($this->app->players()->find(self::id($params)));

        return $this->playerForm($player, [
            'name' => $player['name'],
            'number' => $player['number'] === null ? '' : (string) $player['number'],
            'position' => (string) $player['position'],
        ], []);
    }

    public function updatePlayer(Request $request, array $params): Response
    {
        $this->admin();
        $player = self::found($this->app->players()->find(self::id($params)));
        [$values, $errors] = $this->playerInput($request);
        $photo = $this->uploadPhoto($request, $errors);
        if ($errors !== []) {
            $this->deletePhotoFiles($photo ?? []);

            return $this->playerForm($player, $values, $errors, 422);
        }
        $data = self::playerData($values) + ['archived' => $request->input('archived') === '1' ? 1 : 0];
        if ($photo !== null) {
            $data += $photo;
            $this->deletePhotoFiles($player);
        } elseif ($request->input('remove_photo') === '1') {
            $data += ['photo_file' => null, 'photo_original' => null, 'photo_crop' => null];
            $this->deletePhotoFiles($player);
        } elseif (($recut = $this->recutPhoto($request, $player)) !== null) {
            // Only the cut-out of the existing photo was changed.
            $data += $recut;
            $this->app->images()->delete($player['photo_file']);
        }
        $this->app->players()->update((int) $player['id'], $data);

        $squadIds = self::idList($request, 'squads');
        foreach ($this->app->squads()->forTeam((int) $player['team_id']) as $squad) {
            $members = $this->app->squads()->playerIds((int) $squad['id']);
            $in = in_array((int) $squad['id'], $squadIds, true);
            $members = $in ? [...$members, (int) $player['id']] : array_values(array_diff($members, [(int) $player['id']]));
            $this->app->squads()->setPlayers((int) $squad['id'], (int) $player['team_id'], $members);
        }
        $this->flash('success', 'players.saved', ['name' => $values['name']]);

        return $this->redirect('/admin/teams/' . $player['team_id'] . '#players');
    }

    public function deletePlayer(Request $request, array $params): Response
    {
        $this->admin();
        $player = self::found($this->app->players()->find(self::id($params)));
        if ($this->app->players()->isUsed((int) $player['id'])) {
            $this->app->players()->update((int) $player['id'], ['archived' => 1]);
            $this->flash('success', 'players.archived_instead', ['name' => (string) $player['name']]);
        } else {
            $this->deletePhotoFiles($player);
            $this->app->players()->delete((int) $player['id']);
            $this->flash('success', 'players.deleted', ['name' => (string) $player['name']]);
        }

        return $this->redirect('/admin/teams/' . $player['team_id'] . '#players');
    }

    public function createSquad(Request $request, array $params): Response
    {
        $this->admin();
        $team = self::found($this->app->teams()->find(self::id($params)));
        $name = $request->input('name');
        if ($e = Validation::required($name)) {
            $this->flash('error', 'squads.name_required');

            return $this->redirect('/admin/teams/' . $team['id'] . '#squads');
        }
        $id = $this->app->squads()->create((int) $team['id'], $name);
        // A new template starts with all active players; untick who is not part of it.
        $all = array_map(static fn (array $p) => (int) $p['id'], $this->app->players()->forTeam((int) $team['id']));
        $this->app->squads()->setPlayers($id, (int) $team['id'], $all);
        $this->flash('success', 'squads.created', ['name' => $name]);

        return $this->redirect('/admin/squads/' . $id);
    }

    public function editSquad(Request $request, array $params): Response
    {
        $this->admin();
        $squad = self::found($this->app->squads()->find(self::id($params)));

        return $this->render('admin/teams/squad.html.twig', [
            'squad' => $squad,
            'team' => $this->app->teams()->find((int) $squad['team_id']),
            'players' => $this->app->players()->forTeam((int) $squad['team_id']),
            'selected' => $this->app->squads()->playerIds((int) $squad['id']),
        ]);
    }

    public function updateSquad(Request $request, array $params): Response
    {
        $this->admin();
        $squad = self::found($this->app->squads()->find(self::id($params)));
        $name = $request->input('name');
        if (Validation::required($name) === null) {
            $this->app->squads()->rename((int) $squad['id'], $name);
        }
        $this->app->squads()->setPlayers((int) $squad['id'], (int) $squad['team_id'], self::idList($request, 'players'));
        $this->flash('success', 'squads.saved', ['name' => $name !== '' ? $name : (string) $squad['name']]);

        return $this->redirect('/admin/teams/' . $squad['team_id'] . '#squads');
    }

    public function deleteSquad(Request $request, array $params): Response
    {
        $this->admin();
        $squad = self::found($this->app->squads()->find(self::id($params)));
        $this->app->squads()->delete((int) $squad['id']);
        $this->flash('success', 'squads.deleted', ['name' => (string) $squad['name']]);

        return $this->redirect('/admin/teams/' . $squad['team_id'] . '#squads');
    }

    /**
     * @param array<string, mixed> $team
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     * @param array<string, mixed> $playerForm
     */
    private function teamPage(array $team, array $values, array $errors, array $playerForm, int $status = 200): Response
    {
        $teamId = (int) $team['id'];
        $squads = $this->app->squads()->forTeam($teamId);
        $membership = [];
        foreach ($squads as $squad) {
            foreach ($this->app->squads()->playerIds((int) $squad['id']) as $playerId) {
                $membership[$playerId][] = $squad['name'];
            }
        }

        return $this->render('admin/teams/show.html.twig', [
            'team' => $team,
            'values' => [
                'name' => (string) ($values['name'] ?? ''),
                'short_name' => (string) ($values['short_name'] ?? ''),
                'color' => (string) ($values['color'] ?? ''),
            ],
            'errors' => $errors,
            'players' => $this->app->players()->forTeam($teamId, true),
            'squads' => $squads,
            'membership' => $membership,
            'player_values' => ($playerForm['values'] ?? []) + ['name' => '', 'number' => '', 'position' => ''],
            'player_errors' => $playerForm['errors'] ?? [],
            'used' => $this->app->teams()->isUsed($teamId),
        ], $status);
    }

    /**
     * @param array<string, mixed> $player
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function playerForm(array $player, array $values, array $errors, int $status = 200): Response
    {
        $teamId = (int) $player['team_id'];
        $squads = $this->app->squads()->forTeam($teamId);
        $memberOf = [];
        foreach ($squads as $squad) {
            if (in_array((int) $player['id'], $this->app->squads()->playerIds((int) $squad['id']), true)) {
                $memberOf[] = (int) $squad['id'];
            }
        }

        return $this->render('admin/teams/player.html.twig', [
            'player' => $player,
            'team' => $this->app->teams()->find($teamId),
            'squads' => $squads,
            'member_of' => $memberOf,
            'values' => $values,
            'errors' => $errors,
        ], $status);
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} */
    private function teamInput(Request $request): array
    {
        $values = [
            'name' => $request->input('name'),
            'short_name' => $request->input('short_name'),
            'color' => $request->input('use_color') === '1' ? $request->input('color') : '',
        ];
        $errors = [];
        if ($e = Validation::required($values['name'])) {
            $errors['name'] = $e;
        }
        if (mb_strlen($values['short_name']) > 20) {
            $errors['short_name'] = 'validation.too_long';
        }
        if ($values['color'] !== '' && Branding::validColor($values['color']) === null) {
            $errors['color'] = 'validation.color';
        }

        return [$values, $errors];
    }

    /** @param array<string, string> $values @return array<string, string|null> */
    private static function teamData(array $values): array
    {
        return [
            'name' => $values['name'],
            'short_name' => $values['short_name'] !== '' ? $values['short_name'] : null,
            'color' => Branding::validColor($values['color']),
        ];
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} */
    private function playerInput(Request $request): array
    {
        $values = [
            'name' => $request->input('name'),
            'number' => ltrim($request->input('number'), '#'),
            'position' => $request->input('position'),
        ];
        $errors = [];
        if ($e = Validation::required($values['name'])) {
            $errors['name'] = $e;
        }
        if ($e = Validation::optionalInt($values['number'], 0, 999)) {
            $errors['number'] = $e;
        }
        if (mb_strlen($values['position']) > 40) {
            $errors['position'] = 'validation.too_long';
        }

        return [$values, $errors];
    }

    /** @param array<string, string> $values @return array<string, mixed> */
    private static function playerData(array $values): array
    {
        return [
            'name' => $values['name'],
            'number' => $values['number'] === '' ? null : (int) $values['number'],
            'position' => $values['position'] !== '' ? $values['position'] : null,
        ];
    }

    /**
     * Stores an uploaded player photo with the cut-out chosen in the crop tool.
     *
     * @param array<string, string> $errors
     * @return array{photo_file: string, photo_original: string, photo_crop: string|null}|null null when no file was chosen or on error
     */
    private function uploadPhoto(Request $request, array &$errors): ?array
    {
        $file = $request->file('photo');
        if ($file === null) {
            return null;
        }
        $crop = self::cropInput($request);
        try {
            $stored = $this->app->images()->storePhoto($file, $crop);
        } catch (\InvalidArgumentException $e) {
            $errors['photo'] = $e->getMessage();

            return null;
        }

        return [
            'photo_file' => $stored['photo'],
            'photo_original' => $stored['original'],
            'photo_crop' => $crop === null ? null : self::cropString($crop),
        ];
    }

    /**
     * A new cut-out of the stored original, if the crop tool sent one that differs from the saved one.
     *
     * @param array<string, mixed> $player
     * @return array{photo_file: string, photo_crop: string}|null
     */
    private function recutPhoto(Request $request, array $player): ?array
    {
        $crop = self::cropInput($request);
        $original = (string) ($player['photo_original'] ?? '');
        if ($crop === null || $original === '' || self::cropString($crop) === (string) $player['photo_crop']) {
            return null;
        }
        $photo = $this->app->images()->recrop($original, $crop);

        return $photo === null ? null : ['photo_file' => $photo, 'photo_crop' => self::cropString($crop)];
    }

    /** @return array{x: float, y: float, size: float}|null */
    private static function cropInput(Request $request): ?array
    {
        return ImageUpload::parseCrop($request->input('crop_x'), $request->input('crop_y'), $request->input('crop_size'));
    }

    /** @param array{x: float, y: float, size: float} $crop */
    private static function cropString(array $crop): string
    {
        return sprintf('%.5F,%.5F,%.5F', $crop['x'], $crop['y'], $crop['size']);
    }

    /** @param array<string, mixed> $player photo_file and photo_original of a player (or of a new upload) */
    private function deletePhotoFiles(array $player): void
    {
        $this->app->images()->delete($player['photo_file'] ?? null);
        $this->app->images()->delete($player['photo_original'] ?? null);
    }

    /** @param list<int> $playerIds */
    private function addToSquads(Request $request, int $teamId, array $playerIds): void
    {
        foreach (self::idList($request, 'squads') as $squadId) {
            $squad = $this->app->squads()->find($squadId);
            if ($squad === null || (int) $squad['team_id'] !== $teamId) {
                continue;
            }
            foreach ($playerIds as $playerId) {
                $this->app->squads()->addPlayer($squadId, $playerId);
            }
        }
    }
}
