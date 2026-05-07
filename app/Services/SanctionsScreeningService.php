<?php

namespace App\Services;

use App\Models\SanctionsEntry;
use App\Models\User;
use App\Models\UserScreening;
use Illuminate\Support\Facades\Log;

class SanctionsScreeningService
{
    /**
     * Minimum similarity score (0–100) to flag a match.
     * 85 = high confidence, reduces false positives.
     * Lower this if you want stricter screening.
     */
    private const FLAG_THRESHOLD  = 85;
    private const BLOCK_THRESHOLD = 95;

    /**
     * Screen a user against all loaded sanctions lists.
     */
    public function screen(User $user, string $trigger = 'manual'): UserScreening
    {
        $namesToCheck = $this->buildNameVariants($user);
        $matches      = [];

        foreach ($namesToCheck as $nameVariant) {
            $normalized = SanctionsEntry::normalizeName($nameVariant);
            $hits       = $this->findCandidates($normalized);

            foreach ($hits as $entry) {
                $score = $this->fuzzyScore($normalized, $entry->full_name_normalized);

                // Also check aliases
                foreach ($entry->aliases_normalized ?? [] as $alias) {
                    $aliasScore = $this->fuzzyScore($normalized, $alias);
                    $score      = max($score, $aliasScore);
                }

                if ($score >= self::FLAG_THRESHOLD) {
                    $matches[] = [
                        'sanctions_entry_id' => $entry->id,
                        'source'             => $entry->source,
                        'matched_name'       => $entry->full_name,
                        'queried_name'       => $nameVariant,
                        'score'              => $score,
                        'is_pep'             => $entry->is_pep,
                        'program'            => $entry->program,
                        'entry_type'         => $entry->entry_type,
                    ];
                }
            }
        }

        // Deduplicate by entry ID, keep highest score
        $matches = collect($matches)
            ->groupBy('sanctions_entry_id')
            ->map(fn($g) => $g->sortByDesc('score')->first())
            ->values()
            ->toArray();

        $status = $this->resolveStatus($matches);

        $screening = UserScreening::create([
            'user_id' => $user->id,
            'status'  => $status,
            'trigger' => $trigger,
            'matches' => $matches,
        ]);

        $user->update([
            'screening_status' => $status,
            'last_screened_at' => now(),
        ]);

        if (in_array($status, ['flagged', 'blocked'])) {
            $this->escalate($user, $screening);
        }

        return $screening;
    }

    /**
     * Build all name variants to check — full name, reversed, with/without middle name.
     */
   private function buildNameVariants(User $user): array
    {
        // Read full_name from kyc_verifications if available
        $kycName = \App\Models\KycVerification::where('user_id', $user->id)
            ->where('status', 'approved')
            ->value('full_name');

        $variants = array_filter(array_unique([
            $kycName,
            $user->name,
            $kycName ? implode(' ', array_reverse(explode(' ', $kycName))) : null,
        ]));

        return array_values($variants);
    }

    /**
     * Fetch candidate entries from DB using trigram-style prefix matching.
     * Gets all entries where at least the first word of the normalized name matches.
     */
    private function findCandidates(string $normalizedName): \Illuminate\Support\Collection
    {
        // Extract first meaningful word (usually surname)
        $words = explode(' ', $normalizedName);
        $firstWord = $words[0] ?? '';

        if (strlen($firstWord) < 2) {
            return collect();
        }

        return SanctionsEntry::where('full_name_normalized', 'like', "%{$firstWord}%")
            ->orWhereJsonContains('aliases_normalized', $normalizedName)
            ->limit(50)
            ->get();
    }

    /**
     * Fuzzy similarity score between two normalized strings (0–100).
     * Uses similar_text + Levenshtein combination for best accuracy.
     */
    private function fuzzyScore(string $a, string $b): int
    {
        if ($a === $b) return 100;
        if (empty($a) || empty($b)) return 0;

        // similar_text percentage
        similar_text($a, $b, $similarPct);

        // Levenshtein distance converted to percentage
        $maxLen = max(strlen($a), strlen($b));
        $lev    = levenshtein($a, $b);
        $levPct = (1 - $lev / $maxLen) * 100;

        // Token sort: sort words, compare — handles "John Smith" vs "Smith John"
        $aTokens = explode(' ', $a);
        $bTokens = explode(' ', $b);
        sort($aTokens);
        sort($bTokens);
        similar_text(implode(' ', $aTokens), implode(' ', $bTokens), $tokenPct);

        // Weighted average — token sort gets highest weight for name matching
        return (int) round(($similarPct * 0.25) + ($levPct * 0.25) + ($tokenPct * 0.50));
    }

    /**
     * Resolve overall status from all matches.
     */
    private function resolveStatus(array $matches): string
    {
        if (empty($matches)) return 'clear';

        foreach ($matches as $match) {
            if (! $match['is_pep'] && $match['score'] >= self::BLOCK_THRESHOLD) {
                return 'blocked'; // Hard sanctions hit
            }
        }

        return 'flagged'; // PEP or lower-confidence hit → manual review
    }

    /**
     * Escalate flagged/blocked users to compliance team.
     */
    private function escalate(User $user, UserScreening $screening): void
    {
        Log::channel('telegram')->warning(
            $screening->status === 'blocked'
                ? '🚨 SANCTIONS MATCH — User blocked'
                : '⚠️ PEP/Sanctions flag — Manual review required',
            [
                'user_id'      => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'status'       => $screening->status,
                'match_count'  => count($screening->matches ?? []),
                'top_score'    => collect($screening->matches)->max('score'),
                'screening_id' => $screening->id,
            ]
        );

        if ($screening->status === 'blocked') {
            $user->update(['is_suspended' => true]);
        }
    }
}
