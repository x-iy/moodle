<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core_grades\hook;

use core\hook\described_hook;
use grade_category;
use grade_grade;

/**
 * Hook dispatched at the end of {@see grade_category::aggregate_grades()} once the grade category total
 * has been calculated. Exposes the inputs used, the related grade items, and the associated grade_category
 * and grade_grade objects for the category item. Plugins can use this to compute alternate totals (for example,
 * previewing a course total without penalties) without mutating core database records.
 *
 * @package    core_grades
 * @author     Trisha Milan <trishamilan@catalyst-au.net>
 * @copyright  2025 Monash University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class after_category_aggregation_calculated implements described_hook {
    /**
     * Constructs a new instance of the after_category_aggregation_calculated hook.
     *
     * @param array $gradevaluesused Normalised [0..1] values after limit rules.
     * @param array $gradevaluesprelimit Normalised [0..1] values before limit rules.
     * @param array $items grade_item objects keyed by itemid.
     * @param array $usedweights An array of weights used during the aggregation process.
     * @param array $grademinoverrides An array of minimum grade overrides applied during the aggregation.
     * @param array $grademaxoverrides An array of maximum grade overrides applied during the aggregation.
     * @param int $userid The ID of the user for whom the category aggregation was calculated.
     * @param grade_category $gradecategory The grade category object associated with the aggregation.
     * @param grade_grade $grade The grade_grade object for this user's category item.
     */
    public function __construct(
        /** @var array<int,float> Normalised [0..1] values after limit rules. */
        public readonly array $gradevaluesused,
        /** @var array<int,float> Normalised [0..1] values before limit rules */
        public readonly array $gradevaluesprelimit,
        /** @var array<int,grade_item> grade_item objects keyed by itemid. */
        public readonly array $items,
        /** @var array<int,float> An array of weights used during the aggregation process */
        public readonly array $usedweights,
        /** @var array<int,float> An array of minimum grade overrides applied during the aggregation. */
        public readonly array $grademinoverrides,
        /** @var array<int,float> An array of maximum grade overrides applied during the aggregation. */
        public readonly array $grademaxoverrides,
        /** @var int The ID of the user for whom the category aggregation was calculated. */
        public readonly int $userid,
        /** @var grade_category The grade category object associated with the aggregation. */
        public readonly grade_category $gradecategory,
        /** @var grade_grade The grade_grade object for this user's category item. */
        public readonly grade_grade $grade,
    ) {
    }

    /**
     * Describes the hook purpose.
     *
     * @return string
     */
    public static function get_hook_description(): string {
        return 'Allow plugins to perform actions after a grade category aggregation is calculated. '
            .  'Can be used to compute alternate totals (e.g. preview a course total without grade penalties) '
            .  'without mutating core database records.';
    }

    /**
     * List of tags that describe this hook.
     *
     * @return array
     */
    public static function get_hook_tags(): array {
        return ['core_grades'];
    }
}
