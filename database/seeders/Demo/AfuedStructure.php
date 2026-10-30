<?php

namespace Database\Seeders\Demo;

/**
 * The school structure of Adeyemi Federal University of Education, Ondo, as the old portal (portal.afued.edu.ng) shows
 * it: schools, departments with their codes, student categories with their levels, NCE combinations, sub-departments,
 * course options and matric number prefixes. Entries marked SEEN were read from the old portal's screens; the rest
 * complete the picture and should be checked against the full lists (the screens only showed their first page).
 */
final class AfuedStructure
{
    /** code => [name, seen] */
    public const SCHOOLS = [
        'ANS' => ['Adult and Non-Formal and Special Education', true],
        'ART' => ['Arts', true],
        'ASS' => ['Arts and Social Sciences', true],
        'ECP' => ['Early Childhood Care and Primary Education', true],
        'EDU' => ['Education', true],
        'ESS' => ['Education Support Services', true],
        'GED' => ['General Education', true],
        'LAN' => ['Languages', true],
        'LIB' => ['Library', true],
        'MSS' => ['Management and Social Sciences', true],
        'SCI' => ['Science', true],
        'VTE' => ['Vocational and Technical Education', true],
    ];

    /**
     * Student categories: code => [name, lowest level, highest level, semesters per level, level label, seen].
     * Levels are stored as 100, 200 ...; the label shows them the way the school writes them.
     */
    public const CATEGORIES = [
        'DEG' => ['Degree', 1, 4, 2, '{LEVEL} Level', true],
        'NCE' => ['NCE', 1, 3, 2, 'NCE {N}', true],
        'NPT' => ['NCE Part Time', 1, 3, 2, 'NCE PT {N}', true],
        'NSW' => ['NCE Sandwich', 1, 5, 1, 'NCE Sandwich {N}', true],
        'DPT' => ['Degree Part Time', 1, 5, 2, 'Part Time {N}', true],
        'PRE' => ['Pre-Degree', 1, 1, 2, 'Pre-Degree', true],
        'CRT' => ['Certificate', 1, 1, 2, 'Certificate', true],
    ];

    /**
     * Departments: code => [school, name, matric prefix (degree), matric prefix (NCE), degree title or null, categories, seen].
     * The degree title is how the degree reads on the application form (B.A.Ed., B.Sc.(Ed.), B.Ed., B.Sc.).
     */
    public const DEPARTMENTS = [
        // Adult and Non-Formal and Special Education
        'ANF' => ['ANS', 'Adult and Non-Formal Education', 'ANF', 'ANF', 'B.Ed.', ['DEG', 'NCE', 'NPT', 'NSW'], true],
        'SNE' => ['ANS', 'Special Needs Education', 'SNE', 'SNE', null, ['NCE', 'NPT', 'NSW'], true],
        // Arts (degree departments)
        'CRS' => ['ART', 'Christian Religious Studies', 'REL', null, 'B.A.Ed.', ['DEG', 'PRE'], true],
        'TCA' => ['ART', 'Creative Arts', 'CTA', null, 'B.A.Ed.', ['DEG'], true],
        'EGL' => ['ART', 'English', 'EGL', null, 'B.A.Ed.', ['DEG'], true],
        'FRN' => ['ART', 'French', 'FRN', null, 'B.A.Ed.', ['DEG'], true],
        'FRE' => ['ART', 'French (B.A.)', 'FRE', null, 'B.A.', ['DEG'], true],
        'HAU' => ['ART', 'Hausa', 'HAU', null, 'B.A.Ed.', ['DEG'], true],
        'HIS' => ['ART', 'History and Diplomatic Studies', 'HIS', null, 'B.A.Ed.', ['DEG'], true],
        'IGB' => ['ART', 'Igbo', 'IGB', null, 'B.A.Ed.', ['DEG'], true],
        'YOR' => ['ART', 'Yoruba', 'YOR', null, 'B.A.Ed.', ['DEG'], false],
        'ISL' => ['ART', 'Islamic Studies', 'ISL', null, 'B.A.Ed.', ['DEG'], false],
        // Arts and Social Sciences (NCE and part-time departments)
        'CRN' => ['ASS', 'Christian Religious Studies (NCE)', null, 'CRS', null, ['NCE'], true],
        'ECO' => ['ASS', 'Economics', 'ECO', 'ECO', 'B.Sc.(Ed.)', ['DEG', 'NCE', 'DPT'], true],
        'GEO' => ['ASS', 'Geography', 'GEO', 'GEO', 'B.Sc.(Ed.)', ['DEG', 'NCE', 'DPT'], true],
        'HST' => ['ASS', 'History', null, 'HST', null, ['NCE'], true],
        'RLS' => ['ASS', 'Religious Studies', null, 'RLS', null, ['NCE'], true],
        'THA' => ['ASS', 'Theatre Arts', null, 'THA', null, ['NCE', 'CRT'], true],
        'SOS' => ['ASS', 'Social Studies', 'SOS', 'SOS', 'B.Sc.(Ed.)', ['DEG', 'NCE'], false],
        'POL' => ['ASS', 'Political Science', 'POL', 'POL', 'B.Sc.(Ed.)', ['DEG', 'NCE'], false],
        'MUS' => ['ASS', 'Music', 'MUS', 'MUS', 'B.A.Ed.', ['DEG', 'NCE'], false],
        // Early Childhood Care and Primary Education
        'ECE' => ['ECP', 'Early Childhood Care Education', 'ECE', 'ECE', 'B.Ed.', ['DEG', 'NCE'], false],
        'PES' => ['ECP', 'Primary Education Studies', 'PES', 'PES', 'B.Ed.', ['DEG', 'NCE'], false],
        // Education
        'SND' => ['EDU', 'Special Needs Education (Degree)', 'SNE', null, 'B.Ed.', ['DEG'], true],
        'EDF' => ['EDU', 'Educational Foundations', 'EDF', null, 'B.Ed.', ['DEG'], true],
        'CUI' => ['EDU', 'Curriculum and Instruction', 'CUI', null, 'B.Ed.', ['DEG'], false],
        // Education Support Services: the general studies courses every student takes
        'GSE' => ['ESS', 'General Studies Education', null, null, null, [], true],
        // General Education
        'EDM' => ['GED', 'Educational Management', 'EDM', null, 'B.Ed.', ['DEG', 'DPT'], true],
        'EPC' => ['GED', 'Educational Psychology and Counselling', 'EPC', null, 'B.Ed.', ['DEG', 'DPT'], true],
        'ETE' => ['GED', 'Educational Technology', 'ETE', null, 'B.Ed.', ['DEG'], false],
        // Languages (NCE)
        'ENL' => ['LAN', 'English (NCE)', null, 'ENG', null, ['NCE', 'NPT'], false],
        'FRL' => ['LAN', 'French (NCE)', null, 'FRN', null, ['NCE'], false],
        'YRL' => ['LAN', 'Yoruba (NCE)', null, 'YOR', null, ['NCE', 'NPT'], false],
        'IGL' => ['LAN', 'Igbo (NCE)', null, 'IGB', null, ['NCE'], false],
        'HSL' => ['LAN', 'Hausa (NCE)', null, 'HAU', null, ['NCE'], false],
        'ARB' => ['LAN', 'Arabic', null, 'ARB', null, ['NCE'], false],
        // Library
        'LIS' => ['LIB', 'Library and Information Science', 'LIS', 'LIS', 'B.L.I.S.', ['DEG', 'NCE'], false],
        // Management and Social Sciences
        'ACT' => ['MSS', 'Accounting (B.Sc.)', 'ACT', null, 'B.Sc.', ['DEG'], true],
        'BUS' => ['MSS', 'Business Administration (B.Sc.)', 'BUS', null, 'B.Sc.', ['DEG'], false],
        'ECN' => ['MSS', 'Economics (B.Sc.)', 'ECN', null, 'B.Sc.', ['DEG'], false],
        // Science
        'BIO' => ['SCI', 'Biology', 'BIO', 'BIO', 'B.Sc.(Ed.)', ['DEG', 'NCE', 'NPT'], true],
        'CHM' => ['SCI', 'Chemistry', 'CHM', 'CHM', 'B.Sc.(Ed.)', ['DEG', 'NCE'], false],
        'PHY' => ['SCI', 'Physics', 'PHY', 'PHY', 'B.Sc.(Ed.)', ['DEG', 'NCE'], false],
        'MTH' => ['SCI', 'Mathematics', 'MTH', 'MTH', 'B.Sc.(Ed.)', ['DEG', 'NCE', 'NPT'], false],
        'CSC' => ['SCI', 'Computer Science', 'CSC', 'CSC', 'B.Sc.(Ed.)', ['DEG', 'NCE'], false],
        'ISC' => ['SCI', 'Integrated Science', 'ISC', 'ISC', 'B.Sc.(Ed.)', ['DEG', 'NCE'], true],
        'PHE' => ['SCI', 'Physical and Health Education', 'PHE', 'PHE', 'B.Sc.(Ed.)', ['DEG', 'NCE'], false],
        // Vocational and Technical Education
        'AGE' => ['VTE', 'Agricultural Science', 'ASE', 'AGE', 'B.Sc.(Ed.)', ['DEG', 'NCE'], true],
        'BED' => ['VTE', 'Business Education', 'BED', 'BED', 'B.Sc.(Ed.)', ['DEG', 'NCE'], false],
        'HEC' => ['VTE', 'Home Economics', 'HEC', 'HEC', 'B.Sc.(Ed.)', ['DEG', 'NCE'], false],
        'FAA' => ['VTE', 'Fine and Applied Arts', 'FAA', 'FAA', 'B.A.Ed.', ['DEG', 'NCE'], false],
        'TED' => ['VTE', 'Technical Education', 'TED', 'TED', 'B.Sc.(Ed.)', ['DEG', 'NCE'], false],
    ];

    /**
     * NCE programmes: [first department, second department or null for a double major, category, seen].
     * "Adult and Non-Formal Education/Biology" belongs to the first department and takes courses from both.
     */
    public const COMBINATIONS = [
        ['ANF', null, 'NCE', true], ['ANF', 'BIO', 'NCE', true], ['ANF', 'CHM', 'NCE', true], ['ANF', 'ENL', 'NCE', true],
        ['ANF', 'HST', 'NCE', true], ['ANF', 'ISC', 'NCE', true], ['ANF', 'YRL', 'NCE', true],
        ['ANF', 'YRL', 'NPT', false], ['ANF', null, 'NSW', false],
        ['SNE', 'ENL', 'NCE', false], ['SNE', 'SOS', 'NCE', false], ['SNE', 'ENL', 'NPT', false], ['SNE', null, 'NSW', false],
        ['AGE', null, 'NCE', true],
        ['BIO', 'ISC', 'NCE', true], ['BIO', 'CHM', 'NCE', false], ['BIO', 'ISC', 'NPT', false],
        ['CHM', 'MTH', 'NCE', false], ['PHY', 'MTH', 'NCE', false], ['CSC', 'MTH', 'NCE', false], ['MTH', 'ISC', 'NPT', false],
        ['ISC', null, 'NCE', false], ['PHE', null, 'NCE', false],
        ['ECO', 'SOS', 'NCE', true], ['ECO', 'HST', 'NCE', true], ['GEO', 'ECO', 'NCE', true],
        ['HST', 'SOS', 'NCE', false], ['POL', 'ECO', 'NCE', false], ['SOS', null, 'NCE', false], ['MUS', null, 'NCE', false],
        ['CRN', null, 'NCE', true], ['RLS', 'ECO', 'NCE', true], ['THA', null, 'NCE', false],
        ['ECE', null, 'NCE', false], ['PES', null, 'NCE', false],
        ['ENL', 'YRL', 'NCE', false], ['ENL', 'SOS', 'NCE', false], ['FRL', 'ENL', 'NCE', false], ['IGL', 'ENL', 'NCE', false],
        ['HSL', 'ENL', 'NCE', false], ['ARB', 'ISL', 'NCE', false], ['YRL', 'ENL', 'NPT', false],
        ['LIS', null, 'NCE', false],
        ['BED', null, 'NCE', false], ['HEC', null, 'NCE', false], ['FAA', null, 'NCE', false], ['TED', null, 'NCE', false],
    ];

    /** Other programmes: [department, category, name, application name, seen]. */
    public const OTHER_PROGRAMMES = [
        ['CRS', 'PRE', 'Christian Religious Studies (Pre-Degree)', 'Pre-Degree', true],
        ['THA', 'CRT', 'Theatre Arts (Certificate)', 'Certificate in Theatre Arts', true],
        ['EDM', 'DPT', 'Educational Management (Part Time)', 'B.Ed. (Part Time)', true],
        ['EPC', 'DPT', 'Guidance and Counselling (Part Time)', 'B.Ed. (Part Time)', true],
        ['ECO', 'DPT', 'Economics (Part Time)', 'B.Sc.(Ed.) (Part Time)', true],
        ['GEO', 'DPT', 'Geography (Part Time)', 'B.Sc.(Ed.) (Part Time)', true],
    ];

    /** Course options: [department, category, programme name, options, seen]. */
    public const COURSE_OPTIONS = [
        ['THA', 'CRT', 'Theatre Arts (Certificate)', ['Acting', 'Dance and Choreography', 'Directing', 'Playwriting', 'Technical Theatre'], true],
        ['CRN', 'NCE', 'Christian Religious Studies', ['Christian Religious Studies/Igbo', 'Igbo'], true],
        ['SND', 'DEG', 'Special Needs Education (Degree)', ['Special Needs Education'], true],
        ['EDM', 'DPT', 'Educational Management (Part Time)', ['Economics', 'English'], true],
    ];

    /** Sub-departments: [department, category, name, seen]. */
    public const SUB_DEPARTMENTS = [
        ['HST', 'NCE', 'History/French', true],
        ['RLS', 'NCE', 'Christian Religious Studies', true],
        ['RLS', 'NCE', 'Christian Religious Studies/Economics', true],
        ['RLS', 'NCE', 'Islamic Religious Studies', true],
        ['EPC', 'DPT', 'Guidance and Counselling', true],
    ];

    /** Courses every student takes: [code, title, units, level, semester, for degree?, for NCE?]. GSE111 was seen. */
    public const GENERAL_COURSES = [
        ['GSE111', 'General English I', 2, 100, 1, true, true],
        ['GSE112', 'General English II', 2, 100, 2, true, true],
        ['GSE121', 'Use of Library and Study Skills', 1, 100, 1, true, true],
        ['GSE122', 'Information and Communication Technology', 2, 100, 2, true, true],
        ['EDU111', 'Introduction to the Teaching Profession', 2, 100, 1, true, true],
        ['EDU112', 'History of Education in Nigeria', 2, 100, 2, true, true],
        ['EDU213', 'Educational Psychology', 2, 200, 1, true, true],
        ['EDU214', 'Curriculum Studies', 2, 200, 2, true, true],
        ['EDU311', 'Research Methods in Education', 2, 300, 1, true, true],
        ['EDU312', 'Teaching Practice', 6, 300, 2, false, true],
        ['EDU411', 'Teaching Practice', 6, 400, 1, true, false],
        ['EDU412', 'Research Project', 4, 400, 2, true, false],
    ];

    /** Key to grade for NCE (ranks as the old portal shows them). Degree uses the university's default scale. */
    public const NCE_GRADES = [
        ['A', 70, 100, 5, 'Distinction', true], ['B', 60, 69.99, 4, 'Credit', true], ['C', 50, 59.99, 3, 'Merit', true],
        ['D', 45, 49.99, 2, 'Pass', true], ['E', 40, 44.99, 1, 'Pass', true], ['F', 0, 39.99, 0, 'Fail', false],
    ];

    public static function school(string $deptCode): string
    {
        return self::DEPARTMENTS[$deptCode][0];
    }

    /** The subject a department teaches, for course titles: "English (NCE)" -> "English". */
    public static function subject(string $deptCode): string
    {
        return trim(preg_replace('/\s*\((NCE|B\.A\.|B\.Sc\.|Degree)\)$/', '', self::DEPARTMENTS[$deptCode][1]));
    }
}
