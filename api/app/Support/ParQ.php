<?php

namespace App\Support;

/**
 * The Physical Activity Readiness Questionnaire.
 *
 * The seven questions are the standard PAR-Q, reproduced in the usual wording
 * because that wording is what makes the answers comparable to every other
 * gym's and what a doctor or an insurer will expect to see. They are **not**
 * editable from the settings screen for the same reason: a gym that reworded
 * question 2 and then relied on the answer would be relying on something
 * nobody else recognises.
 *
 * The gym's own waiver text, which is genuinely theirs, *is* editable — see
 * `GymSettings` and `health.terms_body`.
 *
 * ## Versioning
 *
 * `VERSION` changes whenever the questions do. It is copied onto every
 * completed screening, so an answer set can always be read back against the
 * questions it was actually given. Bump it rather than editing in place.
 *
 * ## Ids
 *
 * Answers are stored keyed by `id`, never by position. Reordering or adding a
 * question must not shift three years of stored answers onto different
 * questions.
 */
final class ParQ
{
    public const VERSION = '1.0';

    /**
     * @return array<int, array{id: string, text: string, flag_detail: string}>
     */
    public static function questions(): array
    {
        return [
            [
                'id' => 'heart_condition',
                'text' => 'Has your doctor ever said that you have a heart condition and that you should only do physical activity recommended by a doctor?',
                'flag_detail' => 'Which condition, and what did your doctor advise?',
            ],
            [
                'id' => 'chest_pain_activity',
                'text' => 'Do you feel pain in your chest when you do physical activity?',
                'flag_detail' => 'When does it happen, and how often?',
            ],
            [
                'id' => 'chest_pain_rest',
                'text' => 'In the past month, have you had chest pain when you were not doing physical activity?',
                'flag_detail' => 'When was the most recent episode?',
            ],
            [
                'id' => 'balance_consciousness',
                'text' => 'Do you lose your balance because of dizziness, or do you ever lose consciousness?',
                'flag_detail' => 'How often, and when did it last happen?',
            ],
            [
                'id' => 'bone_joint',
                'text' => 'Do you have a bone or joint problem that could be made worse by a change in your physical activity?',
                'flag_detail' => 'Which joint, and what movements affect it?',
            ],
            [
                'id' => 'blood_pressure_medication',
                'text' => 'Is your doctor currently prescribing drugs — for example, water pills — for your blood pressure or a heart condition?',
                'flag_detail' => 'Which medication?',
            ],
            [
                'id' => 'other_reason',
                'text' => 'Do you know of any other reason why you should not do physical activity?',
                'flag_detail' => 'Please tell us what it is.',
            ],
        ];
    }

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::questions(), 'id');
    }

    public static function text(string $id): ?string
    {
        foreach (self::questions() as $question) {
            if ($question['id'] === $id) {
                return $question['text'];
            }
        }

        return null;
    }

    /**
     * How many questions were answered yes.
     *
     * A yes is the whole point of PAR-Q: the questionnaire does not score or
     * grade, it simply asks whether anything here applies. One yes means the
     * same thing as seven — talk to the member before they train.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function countFlags(array $answers): int
    {
        $flags = 0;

        foreach (self::ids() as $id) {
            if (! empty($answers[$id]['answer'])) {
                $flags++;
            }
        }

        return $flags;
    }

    /**
     * The questions answered yes, with whatever detail was given.
     *
     * @param  array<string, mixed>  $answers
     * @return array<int, array{id: string, text: string, detail: ?string}>
     */
    public static function flagged(array $answers): array
    {
        $flagged = [];

        foreach (self::questions() as $question) {
            if (! empty($answers[$question['id']]['answer'])) {
                $flagged[] = [
                    'id' => $question['id'],
                    'text' => $question['text'],
                    'detail' => $answers[$question['id']]['detail'] ?? null,
                ];
            }
        }

        return $flagged;
    }
}
