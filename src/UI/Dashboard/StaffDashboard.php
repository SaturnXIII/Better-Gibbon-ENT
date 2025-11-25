<?php
/*
Gibbon: the flexible, open school platform
...
*/

namespace Gibbon\UI\Dashboard;

use Gibbon\Http\Url;
use Gibbon\View\View;
use Gibbon\Services\Format;
use Gibbon\Data\Validator;
use Gibbon\Forms\OutputableInterface;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Contracts\Database\Connection;
use Gibbon\Contracts\Services\Session;
use Gibbon\Tables\Prefab\EnrolmentTable;
use Gibbon\Tables\Prefab\FormGroupTable;
use League\Container\ContainerAwareTrait;
use League\Container\ContainerAwareInterface;
use Gibbon\Domain\System\HookGateway;
use Gibbon\Tables\Prefab\TodaysLessonsTable;
use Gibbon\Tables\Prefab\BehaviourTable;

/**
 * Staff Dashboard View Composer
 *
 * @version  v18
 * @since    v18
 */
class StaffDashboard implements OutputableInterface, ContainerAwareInterface
{
    use ContainerAwareTrait;

    protected $db;
    protected $session;
    protected $formGroupTable;
    protected $enrolmentTable;
    private $settingGateway;
    private $view;

    public function __construct(
        Connection $db,
        Session $session,
        FormGroupTable $formGroupTable,
        EnrolmentTable $enrolmentTable,
        SettingGateway $settingGateway,
        View $view
    ) {
        $this->db = $db;
        $this->session = $session;
        $this->formGroupTable = $formGroupTable;
        $this->enrolmentTable = $enrolmentTable;
        $this->settingGateway = $settingGateway;
        $this->view = $view;
    }

    public function getOutput()
    {
        $output = '<h2>'.
            __('Staff Dashboard').
            '</h2>'.
            "<div class='w-full' style='height:calc(100% - 6rem)'>";

        $dashboardContents = $this->renderDashboard();

        if ($dashboardContents == false) {
            $output .= "<div class='error'>".
                __('There are no records to display.').
                '</div>';
        } else {
            $output .= $dashboardContents;
        }
        $output .= '</div>';

        return $output;
    }

    protected function renderDashboard()
    {
        $guid = $this->session->get('guid');
        $connection2 = $this->db->getConnection();
        $gibbonPersonID = $this->session->get('gibbonPersonID');
        $session = $this->session;

        $return = false;
        $planner = false;

        // PLANNER
        if (isActionAccessible($guid, $connection2, '/modules/Planner/planner.php')) {
            $planner = $this
                ->getContainer()
                ->get(TodaysLessonsTable::class)
                ->create($session->get('gibbonSchoolYearID'), $this->session->get('gibbonPersonID'), 'Teacher')
                ->getOutput();
        }

        // TIMETABLE
        $timetable = false;
        if (
            isActionAccessible($guid, $connection2, '/modules/Timetable/tt.php') && $this->session->get('username') != ''
            && $this->session->get('gibbonRoleIDCurrentCategory') == 'Staff'
        ) {
            $_POST = (new Validator(''))->sanitize($_POST);
            $jsonQuery = [
                'gibbonTTID' => $_GET['gibbonTTID'] ?? '',
                'ttDate' => $_POST['ttDate'] ?? '',
            ];
            $apiEndpoint = (string)Url::fromHandlerRoute('index_tt_ajax.php')->withQueryParams($jsonQuery);

            $timetable .= '<h2>'.__('My Timetable').'</h2>';
            $timetable .= "<div hx-get='".$apiEndpoint."' hx-trigger='load' style='width: 100%; min-height: 40px; text-align: center'>";
            $timetable .= "<img style='margin: 10px 0 5px 0' src='".$this->session->get('absoluteURL')."/themes/Default/img/loading.gif' alt='".__('Loading')."' onclick='return false;' /><br/><p style='text-align: center'>".__('Loading').'</p>';
            $timetable .= '</div>';
        }

        // FORM GROUPS
        $formGroups = array();
        $count = 0;

        $dataFormGroups = array(
            'gibbonPersonIDTutor' => $this->session->get('gibbonPersonID'),
            'gibbonPersonIDTutor2' => $this->session->get('gibbonPersonID'),
            'gibbonPersonIDTutor3' => $this->session->get('gibbonPersonID'),
            'gibbonSchoolYearID' => $this->session->get('gibbonSchoolYearID')
        );
        $sqlFormGroups = 'SELECT * FROM gibbonFormGroup WHERE (gibbonPersonIDTutor=:gibbonPersonIDTutor OR gibbonPersonIDTutor2=:gibbonPersonIDTutor2 OR gibbonPersonIDTutor3=:gibbonPersonIDTutor3) AND gibbonSchoolYearID=:gibbonSchoolYearID';
        $resultFormGroups = $this->db->select($sqlFormGroups, $dataFormGroups);

        $attendanceAccess = isActionAccessible($guid, $connection2, '/modules/Attendance/attendance_take_byFormGroup.php');

        while ($rowFormGroups = $resultFormGroups->fetch()) {
            $formGroups[$count][0] = $rowFormGroups['gibbonFormGroupID'];
            $formGroups[$count][1] = $rowFormGroups['nameShort'];

            $formGroupTable = clone $this->formGroupTable;
            $formGroupTable->build($rowFormGroups['gibbonFormGroupID'], true, false, 'rollOrder, surname, preferredName');
            $formGroupTable->setTitle('');

            if ($rowFormGroups['attendance'] == 'Y' && $attendanceAccess) {
                $formGroupTable->addHeaderAction('attendance', __('Take Attendance'))
                    ->setURL('/modules/Attendance/attendance_take_byFormGroup.php')
                    ->addParam('gibbonFormGroupID', $rowFormGroups['gibbonFormGroupID'])
                    ->setIcon('attendance')
                    ->displayLabel();
            }

            $formGroupTable->addHeaderAction('export', __('Export to Excel'))
                ->setURL('/indexExport.php')
                ->addParam('gibbonFormGroupID', $rowFormGroups['gibbonFormGroupID'])
                ->directLink()
                ->displayLabel();

            $formGroups[$count][2] = $formGroupTable->getOutput();

            // BEHAVIOUR
            $behaviourView = isActionAccessible($guid, $connection2, '/modules/Behaviour/behaviour_view.php');
            if ($behaviourView) {
                $table = $this->getContainer()->get(BehaviourTable::class)->create($this->session->get('gibbonSchoolYearID'), $formGroups[$count][0]);
                $formGroups[$count][3] = $table->getOutput();
            }

            ++$count;
        }

        // BUILD TABS
        $tabs = [];

        if (!empty($planner) || !empty($timetable)) {
            $tabs['Planner'] = [
                'label'   => __('Planner'),
                'content' => $planner.$timetable,
                'icon'    => 'book-open',
            ];
        }

        if (count($formGroups) > 0) {
            foreach ($formGroups as $index => $formGroup) {
                $tabs['Form Group Info'.$index] = [
                    'label'   => $formGroup[1],
                    'content' => $formGroup[2],
                    'icon'    => 'user-group',
                ];
                $tabs['Form Group Behaviour'.$index] = [
                    'label'   => $formGroup[1].' '.__('Behaviour'),
                    'content' => $formGroup[3],
                    'icon'    => 'chat-bubble-text',
                ];
            }
        }

        if (isActionAccessible($guid, $connection2, '/modules/Admissions/report_students_left.php') || isActionAccessible($guid, $connection2, '/modules/Admissions/report_students_new.php')) {
            $tabs['Enrolment'] = [
                'label'   => __('Enrolment'),
                'content' => $this->enrolmentTable->getOutput(),
                'icon'    => 'academic-cap',
            ];
        }

        // DASHBOARD HOOKS
        $hooks = $this->getContainer()->get(HookGateway::class)->getAccessibleHooksByType('Staff Dashboard', $this->session->get('gibbonRoleIDCurrent'));
        foreach ($hooks as $hookData) {
            $this->session->set('module', $hookData['sourceModuleName']);
            $include = $this->session->get('absolutePath').'/modules/'.$hookData['sourceModuleName'].'/'.$hookData['sourceModuleInclude'];

            if (!file_exists($include)) {
                $hookOutput = Format::alert(__('The selected page cannot be displayed due to a hook error.'), 'error');
            } else {
                $hookOutput = include $include;
            }

            $tabs[$hookData['name']] = [
                'label'   => __($hookData['name'], [], $hookData['sourceModuleName']),
                'content' => $hookOutput,
                'icon'    => $hookData['name'],
            ];
        }

        // --- AJOUT DES IFRAMES STAFF COMME POUR STUDENT ---
        $calendarContent  = "<iframe src='https://Your_gibbon_url/dev/calendar.php' style='width:100%; height:600px; border:none;'></iframe>";
        $UserNotesContent = "<iframe src='https://Your_gibbon_url/dev/user-note.php' style='width:100%; height:600px; border:none;'></iframe>";
        $homeworksContent = "<iframe src='https://Your_gibbon_url/dev/view-homeworks.php' style='width:100%; height:600px; border:none;'></iframe>";
        $gradesContent    = "<iframe src='https://Your_gibbon_url/dev/view-note.php' style='width:100%; height:600px; border:none;'></iframe>";
        $addContent       = "<iframe src='https://Your_gibbon_url/dev/add.php' style='width:100%; height:600px; border:none;'></iframe>";
        $tools            = "<iframe src='https://Your_gibbon_url/dev/tools.html' style='width:100%; height:600px; border:none;'></iframe>";
        // Ordre des onglets : Calendar → Homeworks → Global Grades → Add → autres onglets
        $tabs = array_merge(
            ['Grades' => [
                'label'   => __('Grades'),
                'content' => $UserNotesContent,
                'icon'    => 'chart-bar',
            ]],
            ['Homeworks' => [
                'label'   => __('Homeworks'),
                'content' => $homeworksContent,
                'icon'    => 'clipboard-list',
            ]],
            ['Calendar' => [
                'label'   => __('Calendar'),
                'content' => $calendarContent,
                'icon'    => 'calendar',
            ]],
            ['Global Grades' => [
                'label'   => __('Global Grades'),
                'content' => $gradesContent,
                'icon'    => 'chart-bar',
            ]],
            ['Add' => [
                'label'   => __('Add'),
                'content' => $addContent,
                'icon'    => 'plus-circle',
            ]],
               ['Tools' => [
                'label'   => __('Tools'),
                'content' => $tools,
                'icon'    => 'tools',
            ]],
            $tabs // autres onglets existants
        );

        // Onglet par défaut = Homeworks (index 2)
        $defaultTab = 2;

        $return = $this->view->fetchFromTemplate('ui/tabs.twig.html', [
            'selected' => $defaultTab,
            'tabs'     => $tabs,
            'outset'   => true,
            'icons'    => true, // icônes visibles
        ]);

        return $return;
    }
}
