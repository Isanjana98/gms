<?php

namespace App\Support;

use App\Models\RegistrationQuestion;
use Illuminate\Support\Collection;

/**
 * Turning the gym's own questions into validation rules, and the answers
 * back into something worth storing.
 *
 * Separate from the controller because the interesting part is not the HTTP:
 * it is that an answer has to carry the question with it.
 */
final class RegistrationForm
{
    /** Longest a free-text answer may be, by type. */
    private const MAX_TEXT = 255;

    private const MAX_TEXTAREA = 2000;

    /**
     * Validation rules for a set of questions, keyed `custom.<key>`.
     *
     * @param  Collection<int, RegistrationQuestion>  $questions
     * @return array<string, array<int, string>>
     */
    public static function rules(Collection $questions): array
    {
        $rules = [];

        foreach ($questions as $question) {
            $field = "custom.{$question->key}";
            $required = $question->is_required ? 'required' : 'nullable';

            $rules[$field] = match ($question->type) {
                'BOOLEAN' => [$required, 'in:yes,no'],
                'CHOICE' => [$required, 'string', 'in:'.implode(',', $question->options ?? [])],
                'TEXTAREA' => [$required, 'string', 'max:'.self::MAX_TEXTAREA],
                default => [$required, 'string', 'max:'.self::MAX_TEXT],
            };

            if ($question->type === 'BOOLEAN') {
                // The same shape as a PAR-Q "yes": a box to say more in.
                $rules["custom.{$question->key}_detail"] = ['nullable', 'string', 'max:500'];
            }
        }

        return $rules;
    }

    /**
     * Pair each answer with the question as it was worded at this moment.
     *
     * This is the whole point of the class. Storing `{goal: "lose weight"}`
     * alone would mean that editing the question later changes what every
     * past answer appears to be an answer to - the exact failure the PAR-Q
     * avoids by keeping its wording in code, and which these questions are
     * exposed to precisely because they *are* editable.
     *
     * Unanswered optional questions are dropped rather than stored as null:
     * a record of what somebody said should not be padded with questions
     * they declined to answer.
     *
     * @param  Collection<int, RegistrationQuestion>  $questions
     * @param  array<string, mixed>  $input  the validated `custom` array
     * @return array<int, array{key: string, label: string, type: string, answer: mixed, detail?: string|null}>
     */
    public static function answers(Collection $questions, array $input): array
    {
        $answers = [];

        foreach ($questions as $question) {
            $raw = $input[$question->key] ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            $entry = [
                'key' => $question->key,
                'label' => $question->label,
                'type' => $question->type,
                'answer' => $question->type === 'BOOLEAN' ? $raw === 'yes' : $raw,
            ];

            if ($question->type === 'BOOLEAN' && $raw === 'yes') {
                // Detail on a "no" is noise at best and a contradiction at
                // worst, the same reasoning the PAR-Q applies.
                $detail = $input["{$question->key}_detail"] ?? null;

                if (filled($detail)) {
                    $entry['detail'] = $detail;
                }
            }

            $answers[] = $entry;
        }

        return $answers;
    }
}
