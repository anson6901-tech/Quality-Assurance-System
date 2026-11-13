<?php

/*
 * Student Evaluation of Teaching (SET) analysis settings.
 */
return [
    // Report category given to drafts created from an analysis.
    'report_category' => 'Student Evaluation of Teaching (SET)',

    // Percentages above "high" are High Quality; from "acceptable" up to "high" (inclusive)
    // are Acceptable Performance; below "acceptable" is Improvement Required.
    'thresholds' => [
        'high' => 80,
        'acceptable' => 60,
    ],

    // Overall grade bands, highest first: [label, lowest score in the band].
    'grade_bands' => [
        ['Distinction', 80],
        ['Credit', 70],
        ['Good', 65],
        ['Fairly Good', 60],
        ['Average', 50],
        ['Below Average', 45],
        ['Marginal Pass', 40],
        ['Unsatisfactory', 30],
        ['Fail', 0],
    ],

    // Data files may be up to this size.
    'max_upload_kilobytes' => 10240,

    'letterhead' => [
        'university' => 'UNIVERSITY OF HEALTH AND ALLIED SCIENCES',
        'directorate' => 'DIRECTORATE OF QUALITY ASSURANCE',
        'phone' => '0302905015',
        'email' => 'qualityassurance@uhas.edu.gh',
        'reference' => 'DQA/DU-',
        'address' => ['PMB 31', 'Ho, Volta Region', 'Ghana'],
    ],
];
