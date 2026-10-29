<?php

declare(strict_types=1);

/*
| Validation messages for the add-on's own rules, read as
| `statamic-form-settings::validation.<key>`.
*/

return [

    // LikertRatingOnScale and the LikertScale fieldtype: a rating off the scale, a row the question
    // does not list, or a row label left empty in the Control Panel
    'likert_scale' => [
        'not_on_scale' => 'Choose a rating from the scale.',
        'row_label_required' => 'Each row label must have a value.',
        'unknown_row' => 'Rate only the rows this question lists.',
    ],

    // QuizAnswerValidation and the Quiz fieldtype: the answer grid's shape, how many answers are
    // marked correct, and a submitted answer the question does not offer
    'quiz_answer' => [
        'array' => 'The quiz answers must be an array.',
        'not_an_option' => 'Choose an answer from the ones this question offers.',
        'marked_correct' => [
            'exactly_one' => 'Exactly one answer must be marked as correct.',
            'at_least_one' => 'At least one answer must be marked as correct.',
        ],
    ],

    // SubfieldsAny: a group field where every subfield is empty
    'subfields_any' => 'At least one subfield of :attribute must have a value.',

];
