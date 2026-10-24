<?php

declare(strict_types=1);

namespace Crownd\Service;

use Crownd\Database\Database;
use Crownd\Domain\MatchStatus;
use Crownd\Repository\MatchRepository;
use Crownd\Security\RateLimiter;

/**
 * Casting votes. Every device gets a random cookie; per match only its hash is
 * stored, so one device can vote once per match and nobody can tell who voted.
 */
final class VotingService
{
    public const COOKIE = 'crownd_voter';
    public const COOKIE_DAYS = 400;

    /** Ballots per IP address and 10 minutes. High, because a whole stadium can share one mobile IP. */
    public const IP_LIMIT = 200;

    public function __construct(
        private readonly Database $db,
        private readonly MatchRepository $matches,
        private readonly RateLimiter $limiter,
    ) {
    }

    public static function isValidToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{32,64}$/', $token);
    }

    public static function voterHash(int $matchId, string $token): string
    {
        return hash('sha256', 'crownd-ballot|' . $matchId . '|' . $token);
    }

    /**
     * The choices of this device for the match (team id => player id), or null if it has not voted.
     *
     * @return array<int, int>|null
     */
    public function ballotOf(int $matchId, string $token): ?array
    {
        if (!self::isValidToken($token)) {
            return null;
        }
        $ballot = $this->db->one(
            'SELECT id FROM ballots WHERE match_id = ? AND voter_hash = ?',
            [$matchId, self::voterHash($matchId, $token)],
        );
        if ($ballot === null) {
            return null;
        }
        $choices = [];
        foreach ($this->db->all('SELECT team_id, player_id FROM votes WHERE ballot_id = ?', [(int) $ballot['id']]) as $row) {
            $choices[(int) $row['team_id']] = (int) $row['player_id'];
        }

        return $choices;
    }

    /**
     * Casts a ballot. Returns one of:
     * ok, closed, no_cookie, already_voted, empty, invalid, rate_limited.
     *
     * @param array<string, mixed> $match
     * @param array<int, int> $picks team id => player id
     */
    public function cast(array $match, array $picks, string $token, string $ip): string
    {
        if ($match['status'] !== MatchStatus::OPEN) {
            return 'closed';
        }
        if (!self::isValidToken($token)) {
            return 'no_cookie';
        }
        $matchId = (int) $match['id'];
        $teams = [(int) $match['home_team_id'], (int) $match['away_team_id']];

        $valid = [];
        foreach ($picks as $teamId => $playerId) {
            if (!in_array($teamId, $teams, true) || $playerId <= 0) {
                continue;
            }
            if (!in_array($playerId, $this->matches->lineupIds($matchId, $teamId), true)) {
                return 'invalid';
            }
            $valid[$teamId] = $playerId;
        }
        if ($valid === []) {
            return 'empty';
        }
        if (!$this->limiter->hit('ballot_ip', $matchId . '|' . $ip, self::IP_LIMIT, 600)) {
            return 'rate_limited';
        }

        try {
            $this->db->transaction(function (Database $db) use ($matchId, $token, $valid): void {
                $ballotId = $db->insert('ballots', [
                    'match_id' => $matchId,
                    'voter_hash' => self::voterHash($matchId, $token),
                    'created_at' => Database::now(),
                ]);
                foreach ($valid as $teamId => $playerId) {
                    $db->insert('votes', [
                        'ballot_id' => $ballotId,
                        'match_id' => $matchId,
                        'team_id' => $teamId,
                        'player_id' => $playerId,
                    ]);
                }
            });
        } catch (\PDOException $e) {
            if ((string) ($e->errorInfo[1] ?? '') === '1062') {
                return 'already_voted';
            }
            throw $e;
        }

        return 'ok';
    }
}
