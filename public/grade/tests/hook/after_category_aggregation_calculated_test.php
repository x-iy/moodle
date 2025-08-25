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

use advanced_testcase;
use core_grades\hook\after_category_aggregation_calculated;
use grade_category;
use grade_grade;
use grade_item;

/**
 * Test hook listener for core_grades.
 *
 * @package    core_grades
 * @author     Trisha Milan <trishamilan@catalyst-au.net>
 * @copyright  2025 Monash University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class after_category_aggregation_calculated_test extends advanced_testcase {
    /**
     * Reset after test.
     *
     * @return void
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test that the after_category_aggregation_calculated hook allows computation of an alternate "no-penalty" course total.
     * @covers \core_grades\hook\after_category_aggregation_calculated
     */
    public function test_hook_allows_computation_of_no_penalty_course_total(): void {
        // Create a test course and one user.
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        // Create two manual grade items. We'll use Weighted mean of grades.
        // Grade Item A: max 100, Grade Item B: max 100.
        $gradeitema = new grade_item([
            'courseid' => $course->id, 'itemtype' => 'manual', 'itemname' => 'A',
            'gradetype' => GRADE_TYPE_VALUE, 'grademax' => 100, 'grademin' => 0,
        ]);
        $gradeitema->insert();
        $gradeitemb = new grade_item([
            'courseid' => $course->id, 'itemtype' => 'manual', 'itemname' => 'B',
            'gradetype' => GRADE_TYPE_VALUE, 'grademax' => 100, 'grademin' => 0,
        ]);
        $gradeitemb->insert();

        // Ensure the course total uses Weighted mean of grades aggregation.
        $coursecat = grade_category::fetch_course_category($course->id);
        $coursecat->aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN;
        $coursecat->update();

        // Set weights to 0.6 and 0.4 respectively.
        $gradeitema->aggregationcoef = 0.6;
        $gradeitema->update();
        $gradeitemb->aggregationcoef = 0.4;
        $gradeitemb->update();

        // Grade Item A no penalty grade: 60/100, finalgrade with penalty: 40, deductedmark: 20.
        // Grade Item B no penalty grade: 80/100, finalgrade with penalty: 40, deductedmark: 40.
        foreach ([[$gradeitema, 40, 20], [$gradeitemb, 40, 40]] as [$gi, $final, $deductedmark]) {
            $g = new grade_grade(['itemid' => $gi->id, 'userid' => $user->id]);
            $g->grade_item = $gi;
            $g->finalgrade = $final;
            $g->deductedmark = $deductedmark;
            $g->rawgrademin = $gi->grademin;
            $g->rawgrademax = $gi->grademax;
            $g->insert('test');
        }

        $gradevaluesstorage = [];
        $testcallback = function (after_category_aggregation_calculated $hook) use (&$gradevaluesstorage): void {
            // First modify gradevalues to include deductedmarks.
            $gradevalues = [];
            $categorygradevalues = [];
            foreach ($hook->gradevaluesprelimit as $itemid => $val) {
                $gradevalues[$itemid] = $val;
                $g = grade_grade::fetch(['itemid' => $itemid, 'userid' => $hook->userid]);
                if (!$g || $g->is_overridden() || $g->finalgrade === null) {
                    continue;
                }
                // Check for user specific grade min/max overrides.
                $usergrademin = isset($hook->grademinoverrides[$itemid]) ?
                    $hook->grademinoverrides[$itemid] : $hook->items[$itemid]->grademin;
                $usergrademax = isset($hook->grademaxoverrides[$itemid]) ?
                    $hook->grademaxoverrides[$itemid] : $hook->items[$itemid]->grademax;
                $deductedmark = grade_grade::standardise_score((float)$g->deductedmark, $usergrademin, $usergrademax, 0, 1);
                $gradevalues[$itemid] = $val + $deductedmark;
                if (isset($gradevaluesstorage[$itemid][$hook->userid])) {
                    $categorygradevalues[$itemid] = $gradevaluesstorage[$itemid][$hook->userid];
                }
            }

            $normalisedgradevalues = [];
            if (!empty($categorygradevalues)) {
                // Normalize the grades first - all will have value 0...1
                // ungraded items are not used in aggregation.
                foreach ($categorygradevalues as $itemid => $v) {
                    // Check for user specific grade min/max overrides.
                    $usergrademin = isset($hook->grademinoverrides[$itemid]) ?
                        $hook->grademinoverrides[$itemid] : $hook->items[$itemid]->grademin;
                    $usergrademax = isset($hook->grademaxoverrides[$itemid]) ?
                        $hook->grademaxoverrides[$itemid] : $hook->items[$itemid]->grademax;
                    if ($hook->gradecategory->aggregation == GRADE_AGGREGATE_SUM) {
                        // Assume that the grademin is 0 when standardising the score, to preserve negative grades.
                        $normalisedgradevalues[$itemid] = grade_grade::standardise_score($v, 0, $usergrademax, 0, 1);
                    } else {
                        $normalisedgradevalues[$itemid] = grade_grade::standardise_score($v, $usergrademin, $usergrademax, 0, 1);
                    }
                }
            }

            asort($gradevalues, SORT_NUMERIC);
            if ($hook->gradecategory->can_apply_limit_rules()) {
                $hook->gradecategory->apply_limit_rules($gradevalues, $hook->items);
            }

            $updatedgradevalues = $normalisedgradevalues + $gradevalues;
            $usedweights = $hook->usedweights;
            $result = $hook->gradecategory->aggregate_values_and_adjust_bounds(
                $updatedgradevalues,
                $hook->items,
                $usedweights,
                $hook->grademinoverrides,
                $hook->grademaxoverrides
            );

            if ($hook->gradecategory->aggregation == GRADE_AGGREGATE_SUM) {
                // The natural aggregation always displays the range as coming from 0 for categories.
                // However, when we bind the grade we allow for negative values.
                $result['grademin'] = 0;
            }

            $finalgrade = grade_grade::standardise_score($result['grade'], 0, 1, $result['grademin'], $result['grademax']);
            $boundedgrade = $hook->gradecategory->grade_item->bounded_grade($finalgrade);
            $gradevaluesstorage[$hook->gradecategory->grade_item->id][$hook->userid] = grade_floatval($boundedgrade);
        };

        // Redirect the hook to our test callback, which calculates the no-penalty course total.
        $this->redirectHook(after_category_aggregation_calculated::class, $testcallback);

        // Trigger the after_category_aggregation_calculated hook.
        $result = grade_regrade_final_grades($course->id);

        $courseitem = grade_item::fetch_course_item($course->id);
        $coursegrade = grade_grade::fetch(['itemid' => $courseitem->id, 'userid' => $user->id]);
        $coursetotalwithpenalty = $coursegrade->finalgrade;
        $coursetotalwithoutpenalty = $gradevaluesstorage[$courseitem->id][$user->id];

        // Verify that the final grade with penalty is 40.
        $this->assertEquals('40.00000', $coursetotalwithpenalty);

        // Verify that the final grade without penalty applied is 68.
        $this->assertEquals('68.00000', $coursetotalwithoutpenalty);

        // Stop redirection after the test.
        $this->stopHookRedirections();
    }
}
