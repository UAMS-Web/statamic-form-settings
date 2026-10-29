<?php

declare(strict_types=1);

use UAMSWeb\FormSettings\Fieldtypes\LikertScale;

/**
 * Pins every preset Likert answer set, label by label and in order.
 *
 * The table is the fieldtype's whole vocabulary: a stored answer is a point,
 * and the label shown to a respondent (and read back by the survey scorer) is
 * looked up here. A changed or dropped label would silently re-word every
 * stored answer, so each set is asserted exactly.
 *
 * @command  vendor/bin/pest tests/Unit/Fieldtypes/LikertScaleOptionsTest.php
 */
covers(LikertScale::class);

/**
 * Every preset answer set, by scale type and number of points.
 *
 * @return array<string, array<int, array<int, string>>>
 */
function likertPresetScales(): array
{
    return [
        'agreement' => [
            2 => [2 => 'Agree', 1 => 'Disagree'],
            3 => [3 => 'Agree', 2 => 'Neutral', 1 => 'Disagree'],
            4 => [4 => 'Strongly Agree', 3 => 'Agree', 2 => 'Disagree', 1 => 'Strongly Disagree'],
            5 => [5 => 'Strongly Agree', 4 => 'Agree', 3 => 'Neutral', 2 => 'Disagree', 1 => 'Strongly Disagree'],
            6 => [6 => 'Strongly Agree', 5 => 'Moderately Agree', 4 => 'Slightly Agree', 3 => 'Slightly Disagree', 2 => 'Moderately Disagree', 1 => 'Strongly Disagree'],
            7 => [7 => 'Strongly Agree', 6 => 'Moderately Agree', 5 => 'Slightly Agree', 4 => 'Neutral', 3 => 'Slightly Disagree', 2 => 'Moderately Disagree', 1 => 'Strongly Disagree'],
            8 => [8 => 'Very Strongly Agree', 7 => 'Strongly Agree', 6 => 'Moderately Agree', 5 => 'Slightly Agree', 4 => 'Slightly Disagree', 3 => 'Moderately Disagree', 2 => 'Strongly Disagree', 1 => 'Very Strongly Disagree'],
            9 => [9 => 'Very Strongly Agree', 8 => 'Strongly Agree', 7 => 'Moderately Agree', 6 => 'Slightly Agree', 5 => 'Neutral', 4 => 'Slightly Disagree', 3 => 'Moderately Disagree', 2 => 'Strongly Disagree', 1 => 'Very Strongly Disagree'],
            10 => [10 => 'Extremely Strongly Agree', 9 => 'Very Strongly Agree', 8 => 'Strongly Agree', 7 => 'Moderately Agree', 6 => 'Slightly Agree', 5 => 'Slightly Disagree', 4 => 'Moderately Disagree', 3 => 'Strongly Disagree', 2 => 'Very Strongly Disagree', 1 => 'Extremely Strongly Disagree'],
        ],
        'experience' => [
            2 => [2 => 'Positive', 1 => 'Negative'],
            3 => [3 => 'Positive', 2 => 'Neutral', 1 => 'Negative'],
            4 => [4 => 'Very Positive', 3 => 'Somewhat Positive', 2 => 'Somewhat Negative', 1 => 'Very Negative'],
            5 => [5 => 'Very Positive', 4 => 'Somewhat Positive', 3 => 'Neutral', 2 => 'Somewhat Negative', 1 => 'Very Negative'],
            6 => [6 => 'Very Positive', 5 => 'Moderately Positive', 4 => 'Somewhat Positive', 3 => 'Somewhat Negative', 2 => 'Moderately Negative', 1 => 'Very Negative'],
            7 => [7 => 'Very Positive', 6 => 'Moderately Positive', 5 => 'Somewhat Positive', 4 => 'Neutral', 3 => 'Somewhat Negative', 2 => 'Moderately Negative', 1 => 'Very Negative'],
            8 => [8 => 'Extremely Positive', 7 => 'Very Positive', 6 => 'Moderately Positive', 5 => 'Somewhat Positive', 4 => 'Somewhat Negative', 3 => 'Moderately Negative', 2 => 'Very Negative', 1 => 'Extremely Negative'],
            9 => [9 => 'Extremely Positive', 8 => 'Very Positive', 7 => 'Moderately Positive', 6 => 'Somewhat Positive', 5 => 'Neutral', 4 => 'Somewhat Negative', 3 => 'Moderately Negative', 2 => 'Very Negative', 1 => 'Extremely Negative'],
            10 => [10 => 'Completely Positive', 9 => 'Extremely Positive', 8 => 'Very Positive', 7 => 'Moderately Positive', 6 => 'Somewhat Positive', 5 => 'Somewhat Negative', 4 => 'Moderately Negative', 3 => 'Very Negative', 2 => 'Extremely Negative', 1 => 'Completely Negative'],
        ],
        'frequency' => [
            2 => [2 => 'Often (at least 50% of the time)', 1 => 'Rarely (less than 50% of the time)'],
            3 => [3 => 'Often (about 70% of the time)', 2 => 'Sometimes (about 50% of the time)', 1 => 'Rarely (about 30% of the time)'],
            4 => [4 => 'Always (100% of the time)', 3 => 'Often (about 70% of the time)', 2 => 'Rarely (about 30% of the time)', 1 => 'Never (0% of the time)'],
            5 => [5 => 'Always (100% of the time)', 4 => 'Often (about 70% of the time)', 3 => 'Sometimes (about 50% of the time)', 2 => 'Rarely (about 30% of the time)', 1 => 'Never (0% of the time)'],
            6 => [6 => 'Always (100% of the time)', 5 => 'Very Often (about 80% of the time)', 4 => 'Often (about 70% of the time)', 3 => 'Rarely (about 30% of the time)', 2 => 'Very Rarely (about 15% of the time)', 1 => 'Never (0% of the time)'],
            7 => [7 => 'Always (100% of the time)', 6 => 'Very Often (about 80% of the time)', 5 => 'Often (about 70% of the time)', 4 => 'Sometimes (about 50% of the time)', 3 => 'Rarely (about 30% of the time)', 2 => 'Very Rarely (about 15% of the time)', 1 => 'Never (0% of the time)'],
            8 => [8 => 'Always (100% of the time)', 7 => 'Very Often (about 80% of the time)', 6 => 'Often (about 70% of the time)', 5 => 'Fairly Often (about 60% of the time)', 4 => 'Rarely (about 30% of the time)', 3 => 'Very Rarely (about 15% of the time)', 2 => 'Hardly Ever (about 5% of the time)', 1 => 'Never (0% of the time)'],
            9 => [9 => 'Always (100% of the time)', 8 => 'Very Often (about 80% of the time)', 7 => 'Often (about 70% of the time)', 6 => 'Fairly Often (about 60% of the time)', 5 => 'Sometimes (about 50% of the time)', 4 => 'Rarely (about 30% of the time)', 3 => 'Very Rarely (about 15% of the time)', 2 => 'Hardly Ever (about 5% of the time)', 1 => 'Never (0% of the time)'],
            10 => [10 => 'Always (100% of the time)', 9 => 'Fairly Often (about 90% of the time)', 8 => 'Very Often (about 80% of the time)', 7 => 'Often (about 70% of the time)', 6 => 'Fairly Often (about 60% of the time)', 5 => 'Sometimes (about 50% of the time)', 4 => 'Rarely (about 30% of the time)', 3 => 'Very Rarely (about 15% of the time)', 2 => 'Hardly Ever (about 5% of the time)', 1 => 'Never (0% of the time)'],
        ],
        'frequency_no_always' => [
            2 => [2 => 'Often (at least 50% of the time)', 1 => 'Rarely (less than 50% of the time)'],
            3 => [3 => 'Often (about 70% of the time)', 2 => 'Sometimes (about 50% of the time)', 1 => 'Rarely (about 30% of the time)'],
            4 => [4 => 'Often (about 70% of the time)', 3 => 'Sometimes (about 50% of the time)', 2 => 'Rarely (about 30% of the time)', 1 => 'Never (0% of the time)'],
            5 => [5 => 'Very Often (about 80% of the time)', 4 => 'Often (about 70% of the time)', 3 => 'Sometimes (about 50% of the time)', 2 => 'Rarely (about 30% of the time)', 1 => 'Never (0% of the time)'],
            6 => [6 => 'Very Often (about 80% of the time)', 5 => 'Often (about 70% of the time)', 4 => 'Sometimes (about 50% of the time)', 3 => 'Rarely (about 30% of the time)', 2 => 'Very Rarely (about 15% of the time)', 1 => 'Never (0% of the time)'],
            7 => [7 => 'Very Often (about 80% of the time)', 6 => 'Often (about 70% of the time)', 5 => 'Sometimes (about 50% of the time)', 4 => 'Rarely (about 30% of the time)', 3 => 'Very Rarely (about 15% of the time)', 2 => 'Hardly Ever (about 5% of the time)', 1 => 'Never (0% of the time)'],
            8 => [8 => 'Very Often (about 80% of the time)', 7 => 'Often (about 70% of the time)', 6 => 'Fairly Often (about 60% of the time)', 5 => 'Sometimes (about 50% of the time)', 4 => 'Rarely (about 30% of the time)', 3 => 'Very Rarely (about 15% of the time)', 2 => 'Hardly Ever (about 5% of the time)', 1 => 'Never (0% of the time)'],
            9 => [9 => 'Almost Always (about 90% of the time)', 8 => 'Very Often (about 80% of the time)', 7 => 'Often (about 70% of the time)', 6 => 'Fairly Often (about 60% of the time)', 5 => 'Sometimes (about 50% of the time)', 4 => 'Rarely (about 30% of the time)', 3 => 'Very Rarely (about 15% of the time)', 2 => 'Hardly Ever (about 5% of the time)', 1 => 'Never (0% of the time)'],
            10 => [10 => 'Almost Always (about 90% of the time)', 9 => 'Very Often (about 80% of the time)', 8 => 'Often (about 70% of the time)', 7 => 'Fairly Often (about 60% of the time)', 6 => 'Sometimes (about 50% of the time)', 5 => 'Occasionally (about 40% of the time)', 4 => 'Rarely (about 30% of the time)', 3 => 'Very Rarely (about 15% of the time)', 2 => 'Hardly Ever (about 5% of the time)', 1 => 'Never (0% of the time)'],
        ],
        'likelihood' => [
            2 => [2 => 'Likely', 1 => 'Unlikely'],
            3 => [3 => 'Likely', 2 => 'Neutral', 1 => 'Unlikely'],
            4 => [4 => 'Very Likely', 3 => 'Likely', 2 => 'Unlikely', 1 => 'Very Unlikely'],
            5 => [5 => 'Very Likely', 4 => 'Likely', 3 => 'Neutral', 2 => 'Unlikely', 1 => 'Very Unlikely'],
            6 => [6 => 'Extremely Likely', 5 => 'Very Likely', 4 => 'Somewhat Likely', 3 => 'Somewhat Unlikely', 2 => 'Very Unlikely', 1 => 'Extremely Unlikely'],
            7 => [7 => 'Extremely Likely', 6 => 'Very Likely', 5 => 'Somewhat Likely', 4 => 'Neutral', 3 => 'Somewhat Unlikely', 2 => 'Very Unlikely', 1 => 'Extremely Unlikely'],
            8 => [8 => 'Extremely Likely', 7 => 'Very Likely', 6 => 'Moderately Likely', 5 => 'Slightly Likely', 4 => 'Slightly Unlikely', 3 => 'Moderately Unlikely', 2 => 'Very Unlikely', 1 => 'Extremely Unlikely'],
            9 => [9 => 'Extremely Likely', 8 => 'Very Likely', 7 => 'Moderately Likely', 6 => 'Slightly Likely', 5 => 'Neutral', 4 => 'Slightly Unlikely', 3 => 'Moderately Unlikely', 2 => 'Very Unlikely', 1 => 'Extremely Unlikely'],
            10 => [10 => 'Completely Likely', 9 => 'Extremely Likely', 8 => 'Very Likely', 7 => 'Moderately Likely', 6 => 'Slightly Likely', 5 => 'Slightly Unlikely', 4 => 'Moderately Unlikely', 3 => 'Very Unlikely', 2 => 'Extremely Unlikely', 1 => 'Completely Unlikely'],
        ],
        'quality' => [
            2 => [2 => 'Good', 1 => 'Poor'],
            3 => [3 => 'Good', 2 => 'Fair', 1 => 'Poor'],
            4 => [4 => 'Very Good', 3 => 'Good', 2 => 'Poor', 1 => 'Very Poor'],
            5 => [5 => 'Very Good', 4 => 'Good', 3 => 'Fair', 2 => 'Poor', 1 => 'Very Poor'],
            6 => [6 => 'Excellent', 5 => 'Very Good', 4 => 'Good', 3 => 'Poor', 2 => 'Very Poor', 1 => 'Terrible'],
            7 => [7 => 'Excellent', 6 => 'Very Good', 5 => 'Good', 4 => 'Fair', 3 => 'Poor', 2 => 'Very Poor', 1 => 'Terrible'],
            8 => [8 => 'Outstanding', 7 => 'Excellent', 6 => 'Very Good', 5 => 'Good', 4 => 'Poor', 3 => 'Very Poor', 2 => 'Terrible', 1 => 'Extremely Poor'],
            9 => [9 => 'Outstanding', 8 => 'Excellent', 7 => 'Very Good', 6 => 'Good', 5 => 'Fair', 4 => 'Poor', 3 => 'Very Poor', 2 => 'Terrible', 1 => 'Extremely Poor'],
            10 => [10 => 'Perfect', 9 => 'Outstanding', 8 => 'Excellent', 7 => 'Very Good', 6 => 'Good', 5 => 'Poor', 4 => 'Very Poor', 3 => 'Terrible', 2 => 'Extremely Poor', 1 => 'Awful'],
        ],
        'satisfaction' => [
            2 => [2 => 'Satisfied', 1 => 'Dissatisfied'],
            3 => [3 => 'Satisfied', 2 => 'Neutral', 1 => 'Dissatisfied'],
            4 => [4 => 'Very Satisfied', 3 => 'Satisfied', 2 => 'Dissatisfied', 1 => 'Very Dissatisfied'],
            5 => [5 => 'Very Satisfied', 4 => 'Satisfied', 3 => 'Neutral', 2 => 'Dissatisfied', 1 => 'Very Dissatisfied'],
            6 => [6 => 'Extremely Satisfied', 5 => 'Very Satisfied', 4 => 'Satisfied', 3 => 'Dissatisfied', 2 => 'Very Dissatisfied', 1 => 'Extremely Dissatisfied'],
            7 => [7 => 'Extremely Satisfied', 6 => 'Very Satisfied', 5 => 'Satisfied', 4 => 'Neutral', 3 => 'Dissatisfied', 2 => 'Very Dissatisfied', 1 => 'Extremely Dissatisfied'],
            8 => [8 => 'Completely Satisfied', 7 => 'Extremely Satisfied', 6 => 'Very Satisfied', 5 => 'Satisfied', 4 => 'Dissatisfied', 3 => 'Very Dissatisfied', 2 => 'Extremely Dissatisfied', 1 => 'Completely Dissatisfied'],
            9 => [9 => 'Completely Satisfied', 8 => 'Extremely Satisfied', 7 => 'Very Satisfied', 6 => 'Satisfied', 5 => 'Neutral', 4 => 'Dissatisfied', 3 => 'Very Dissatisfied', 2 => 'Extremely Dissatisfied', 1 => 'Completely Dissatisfied'],
            10 => [10 => 'Perfectly Satisfied', 9 => 'Completely Satisfied', 8 => 'Extremely Satisfied', 7 => 'Very Satisfied', 6 => 'Satisfied', 5 => 'Dissatisfied', 4 => 'Very Dissatisfied', 3 => 'Extremely Dissatisfied', 2 => 'Completely Dissatisfied', 1 => 'Not at All Satisfied'],
        ],
    ];
}

/**
 * Call the fieldtype's protected preset lookup.
 *
 * @return array<int, string>
 */
function likertPreset(string $type, int $points): array
{
    /** @var array<int, string> $options */
    $options = new ReflectionMethod(LikertScale::class, 'likertOptions')->invoke(new LikertScale, $type, $points);

    return $options;
}

it('returns each preset answer set exactly', function (string $type, int $points, array $expected): void {
    expect(likertPreset($type, $points))->toBe($expected);
})->with(function (): Generator {
    foreach (likertPresetScales() as $type => $sets) {
        foreach ($sets as $points => $labels) {
            yield "{$type} with {$points} points" => [$type, $points, $labels];
        }
    }
});

it('falls back to the agreement scale for an unknown scale type', function (): void {
    expect(likertPreset('mood', 5))->toBe(likertPresetScales()['agreement'][5]);
});

it('returns no answers for a point count outside two to ten', function (int $points): void {
    expect(likertPreset('agreement', $points))->toBe([]);
})->with([1, 11]);
