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
use League\Container\ContainerAwareInterface;
use League\Container\ContainerAwareTrait;
use Gibbon\Domain\System\HookGateway;
use Gibbon\Tables\Prefab\TodaysLessonsTable;

/**
 * Student Dashboard View Composer
 *
 * @version  v18
 * @since    v18
 */
class StudentDashboard implements OutputableInterface, ContainerAwareInterface
{
    use ContainerAwareTrait;

    protected $db;
    protected $session;
    protected $settingGateway;

    private $view;

    public function __construct(Connection $db, Session $session, SettingGateway $settingGateway, View $view)
    {
        $this->db = $db;
        $this->session = $session;
        $this->settingGateway = $settingGateway;
        $this->view = $view;
    }

    public function getOutput()
    {
        $output = '<h2>'.
            __('Student Dashboard').
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

        $planner = false;

        // PLANNER
        if (isActionAccessible($guid, $connection2, '/modules/Planner/planner.php')) {
            $planner = $this
                ->getContainer()
                ->get(TodaysLessonsTable::class)
                ->create($session->get('gibbonSchoolYearID'), $gibbonPersonID, 'Student')
                ->getOutput();
        }

        // TIMETABLE
        $timetable = false;
        if (isActionAccessible($guid, $connection2, '/modules/Timetable/tt.php') && $this->session->get('username') != '') {
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

        // BUILD TABS
        $tabs = [];

        if (!empty($planner) || !empty($timetable)) {
            $tabs['Planner'] = [
                'label'   => __('Planner'),
                'content' => $planner.$timetable,
                'icon'    => 'book-open',
            ];
        }

        // DASHBOARD HOOKS
        $hooks = $this->getContainer()->get(HookGateway::class)->getAccessibleHooksByType('Student Dashboard', $this->session->get('gibbonRoleIDCurrent'));
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

        // --- IFRAMES COMME CALENDAR ---
        $calendarContent  = "<iframe src='https://Your_gibbon_url/gibbon/dev/calendar.php' style='width:100%; height:600px; border:none;'></iframe>";
        $UserNotesContent = "<iframe src='https://Your_gibbon_url/gibbon/dev/user-note.php' style='width:100%; height:600px; border:none;'></iframe>";
        $homeworksContent = "<iframe src='https://Your_gibbon_url/gibbon/dev/view-homeworks.php' style='width:100%; height:600px; border:none;'></iframe>";
        $gradesContent    = "<iframe src='https://Your_gibbon_url/gibbon/dev/view-note.php' style='width:100%; height:600px; border:none;'></iframe>";
        $addContent       = "<iframe src='https://Your_gibbon_url/gibbon/dev/add.php' style='width:100%; height:600px; border:none;'></iframe>";
        $tools            = "<iframe src='https://Your_gibbon_url/gibbon/dev/tools.html' style='width:100%; height:600px; border:none;'></iframe>";

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
            'icons'    => true, // icônes toujours visibles avec CSS si besoin
        ]);

        return $return;
    }
}
