<?php
/**
 * Core/Template.php
 *
 * This class is responsible for all visual output for the application.
 * This class also contains all the functions for parsing data outputs
 * into HTML, including: bbcodes, the data replacement structure, the
 * filters, and other variables used to display application content.
 *
 * @todo    centralize storage of the filters and patterns.
 *
 * @version 1.0
 *
 * @author  Joey Kimsey <JoeyKimsey@thetempusproject.com>
 *
 * @link    https://TheTempusProject.com/Core
 *
 * @license https://opensource.org/licenses/MIT [MIT LICENSE]
 */

namespace TempusProjectCore\Core;

use TempusProjectCore\Classes\Debug as Debug;
use TempusProjectCore\Classes\Issue as Issue;
use TempusProjectCore\Classes\Token as Token;
use TempusProjectCore\Functions\Docroot as Docroot;
use TempusProjectCore\Classes\Config as Config;
use TempusProjectCore\Classes\CustomException as CustomException;
use TempusProjectCore\Classes\Pagination as Pagination;
use \DateTime as DateTimeZone;
use \DateTime as DateTime;

class Template extends Controller
{
    private static $pageLimit = null;
    private static $page = null;
    private static $min = null;
    private static $max = null;
    private static $follow = true;
    private static $index = true;
    private static $defaultPath = null;
    private static $templateName = null;
    private static $templateLocation = null;
    private static $pattern = [];
    private static $values = [];
    private static $options = [];

    /**
     * The constructor automatically sets a few $values and variables
     * the template will need.
     */
    public function __construct()
    {
        Debug::group('Template Constructor', 1);
        self::set('TITLE', 'The Tempus Project');
        self::set('PAGE_DESCRIPTION', '');
        self::set('TOKEN', Token::generate());
        self::set('BASE', Docroot::getAddress());
        self::setRobot();
        Debug::gend();
    }

    /**
     * This function sets the '<template>.tpl' to be used for the rendering of the
     * application. It also calls the template include file via the Template::loadTemplate
     * function and stores the keys to the $values array for the template to use later.
     *
     * @param string $name   - The name of the template you are trying to use.
     *                       ('.', and '_' are valid delimiters and the
     *                       '.tpl' or '.inc.php' are not required.)
     *
     * @todo - Add a check for proper filename.
     */
    public static function setTemplate($name)
    {
        Debug::log("Setting template: $name");
        $docLocation = Docroot::getLocation('template', $name);
        if ($docLocation->error) {
            new CustomException('template', $docLocation->errorString);
            $docLocation = Docroot::getLocation('template', Config::get('main/template'));
        }
        self::$templateLocation = $docLocation->fullPath;
        $load = self::loadTemplate($name);
        foreach ($load as $key => $value) {
            self::set($key, $value);
        }
    }

    /**
     * Checks for, requires, and instantiates the template include file
     * and constructor for the specified template. Uses the class templateName
     * if none is provided.
     *
     * @param  string $name - A custom template name to load the include for.
     *
     * @return array - Returns the values object from the loader file,
     *                 or an empty array.
     *
     * @todo - Add a check for proper filename.
     */
    private static function loadTemplate($name)
    {
        Debug::group('Template Loader', 1);
        $loaderName = strtolower(str_replace('.', '_', $name));
        $docLocation = Docroot::getLocation('templateLoader', $loaderName);
        if ($docLocation->error) {
            new CustomException('templateLoader', $docLocation->errorString);
        } else {
            Debug::log('Requiring template loader: ' . $loaderName);
            require_once $docLocation->fullPath;
            $loaderNameFull = $docLocation->className;
            Debug::log('Calling loader: ' . $docLocation->className);
            $loader = new $docLocation->className;
        }
        Debug::gend();
        if (!empty($loader)) {
            return unserialize($loader->values());
        } else {
            return [];
        }
    }

    /**
     * Sets the current page as noFollow and rebuilds the robots
     * meta tag afterwards.
     *
     * @param  boolean $status - The desired state for noFollow.
     */
    public static function noFollow($status = false)
    {
        self::$follow = (bool) $status;
        self::setRobot();
    }

    /**
     * Sets the current page as noIndex and rebuilds the robots
     * meta tag afterwards.
     *
     * @param  boolean $status - The desired state for noIndex.
     */
    public static function noIndex($status = false)
    {
        self::$index = (bool) $status;
        self::setRobot();
    }

    /**
     * Updates the values array key for ROBOT based on Template variables.
     */
    public static function setRobot()
    {
        if (!self::$index && !self::$follow) {
            self::set('ROBOT', '<meta name="robots" content="noindex,nofollow">');
        } elseif (!self::$index) {
            self::set('ROBOT', '<meta name="robots" content="noindex">');
        } elseif (!self::$follow) {
            self::set('ROBOT', '<meta name="robots" content="nofollow">');
        } else {
            self::set('ROBOT', '');
        }
    }

    /**
     * Prints the parsed and fully rendered page using the specified template from
     * templateLocation.
     */
    public static function render()
    {
        if (empty(self::$templateLocation)) {
            self::setTemplate(Config::get('main/template'));
        }
        // NOTE: This should be the only echo in the system.
        echo self::parse(file_get_contents(self::$templateLocation));
    }

    /**
     * Adds a $key->$value combination to the $values array.
     *
     * @param string $key   The key by which to access this value.
     * @param wild   $value The value being stored.
     *
     * @todo - Add a check for valid $key values
     */
    public static function set($key, $value)
    {
        self::$values[$key] = $value;
    }

    /**
     * Adds a {$name}{/$name} filter to the filters list that can be
     * enabled or disabled (disabled by default).
     *
     * @param string $name    - The filters name.
     * @param string $match   - The regex to look for.
     * @param bool   $enabled - Whether the filter should be enabled or disabled.
     */
    public static function addFilter($name, $match, $enabled = false)
    {
        self::$pattern[$name] = [
            'name'    => $name,
            'match'   => $match,
            'enabled' => $enabled,
        ];
    }

    /**
     * Removes a {$name}{/$name} filter from the filters list.
     *
     * @param string $name - The filters name.
     */
    public static function removeFilter($name)
    {
        unset(self::$pattern[$name]);
    }

    /**
     * Enable a filter.
     *
     * @param string $name - The filters name.
     *
     * @todo - Add a check for valid $name values
     *         Should throw an error if the filter doesn't exist
     */
    public static function enableFilter($name)
    {
        self::$pattern[$name] = [
            'name'    => $name,
            'match'   => self::$pattern[$name]['match'],
            'enabled' => true,
        ];
    }

    /**
     * Disables a filter.
     *
     * @param string $name - The filters name.
     *
     * @todo - Add a check for valid $name values
     *         Should throw an error if the filter doesn't exist
     */
    public static function disableFilter($name)
    {
        self::$pattern[$name] = [
            'name'    => $name,
            'match'   => self::$pattern[$name]['match'],
            'enabled' => false,
        ];
    }

    /**
     * Returns a completely parsed view.
     *
     * NOTE: Results will contain raw HTML.
     *
     * @param string $view - The name of the view you wish to call.
     * @param var    $data - Any data to be used by the view.
     *
     * @return string HTML view.
     */
    public static function standardView($view, $data = null)
    {
        $viewName = str_replace('.', '_', $view);
        $path = Docroot::getFull() . 'Views/view_' . $viewName . '.php';
        if (is_file($path)) {
            Debug::log("Calling Standard View: $viewName");
            if (!empty($data)) {
                return self::parse(file_get_contents($path), $data);
            } else {
                return self::parse(file_get_contents($path));
            }
        } else {
            new CustomException('standardView', $viewName);
        }
    }

    /**
     * This function parses either given html or the current page content and sets
     * the current active page to selected within an html list.
     *
     * @param  string $menu         - The name of the view you wish to add. can be any arbitrary value if $view is
     *                              provided.
     * @param  string $selectString - The string/url you are searching for, default model/controller is used if none is
     *                              provided.
     * @param  string $view         - The html you want parsed, view is generated from menu name if $view is left blank
     *
     * @return string|bool           - returns bool if the menu was added to the page content or
     *                                 returns the parsed view if one was provided with the
     *                                 function call.
     */
    public static function activePageSelect($menu, $selectString = null, $view = null)
    {
        if ($selectString == null) {
            $selectString = CORE_CONTROLLER . '/' . CORE_METHOD;
        }
        $regURL = Docroot::getAddress() . $selectString;
        $regPage = "#\<li(.*)\>\<a(.*)href=\"$regURL\"(.*)\>(.*)\<\/li>#i";
        $regActive = "<li$1 class=\"active\"><a$2href=\"$regURL\"$3>$4</li>";
        if ($view == null) {
            //adds the nav to the main content by default
            $content = true;
            $view = self::$template->standardView($menu);
        }

        if (!preg_match($regPage, $view)) {
            //if you cannot find the item requested, it will default to the base of the item provided
            $newURL = explode('/', $selectString);
            $regURL = Docroot::getAddress() . $newURL[0];
            $regPage = "#\<li(.*)\>\<a(.*)href=\"$regURL\"(.*)\>(.*)\<\/li>#i";
        }

        if (isset($content)) {
            self::$content .= preg_replace($regPage, $regActive, $view);
            return true;
        }
        $view = preg_replace($regPage, $regActive, $view);
        return $view;
    }

    /**
     * Generates all the information we need to visually
     * display pagination within the template.
     */
    private static function paginate()
    {
        $pageData = [];
        if (Pagination::firstPage() != 1) {
            $data[1]['ACTIVEPAGE'] = '';
            $data[1]['PAGENUMBER'] = 1;
            $data[1]['LABEL'] = 'First';
            $pageData[1] = (object) $data[1];
        }
        for ($x = Pagination::firstPage(); $x < Pagination::lastPage(); $x++) {
            if ($x == Pagination::currentPage()) {
                $active = ' class="active"';
            } else {
                $active = '';
            }
            $data[$x]['ACTIVEPAGE'] = $active;
            $data[$x]['PAGENUMBER'] = $x;
            $data[$x]['LABEL'] = $x;
            $pageData[$x] = (object) $data[$x];
        }
        if (Pagination::lastPage() <= Pagination::totalPages()) {
            $x = Pagination::totalPages();
            if ($x == Pagination::currentPage()) {
                $active = ' class="active"';
            } else {
                $active = '';
            }
            $data[$x]['ACTIVEPAGE'] = $active;
            $data[$x]['PAGENUMBER'] = $x;
            $data[$x]['LABEL'] = 'Last';
            $pageData[$x] = (object) $data[$x];
        }
        $pageData = (object) $pageData;
        if (Pagination::totalPages() <= 1) {
            self::set('PAGINATION', '<lb>');
        } else {
            self::set('PAGINATION', self::standardView('pagination', $pageData));
        }
    }

    /**
     * Sets the specified radio button with $x value to checked.
     *
     * @param  string $fieldName - The name of the radio field.
     * @param  string $value     - The value of the field to be selected.
     */
    public static function selectRadio($fieldName, $value)
    {
        $selected = 'CHECKED:' . $fieldName . '=' . $value;
        self::set($selected, 'checked="checked"');
    }

    /**
     * A custom filter for removing annotated sections left from the form template engine.
     *
     * @param string $data - The string being filtered
     *
     * @return string - The filtered $data
     */
    public static function filterFormComponents($data)
    {
        $componentPattern = "#{CHECKED:(.*?)=(.*?)}#s";
        $output = preg_replace($componentPattern, null, $data);

        return $output;
    }

    /**
     * This will add an option to our selected options menu that will
     * automatically be selected when the template is rendered.
     *
     * @param  string $value - The value of the option you want selected.
     */
    public static function selectOption($value)
    {
        $find = "#\<option (.*?)value=\'" . $value . "\'#s";
        $replace = "<option $1value='" . $value . "' selected";
        self::$options[$find] = $replace;
    }

    /**
     * Iterates through the filters list on $data. Leaving only the internal
     * contents of enabled filters and removing all traces of disabled filters.
     *
     * @param string $data - The string being checked for filters
     *
     * @return string - The filtered $data.
     */
    private static function filters($data)
    {
        if (!empty(self::$pattern)) {
            foreach (self::$pattern as $instance) {
                if ($instance['enabled']) {
                    $replace = '$1';
                } else {
                    $replace = null;
                }
                $data = trim(preg_replace($instance['match'], $replace, $data));
            }
        }

        return $data;
    }

    /**
     * A filter for turning legitimate mentions into profile links.
     *
     * @param string $data - The string being filtered
     *
     * @return string - The filtered $data
     */
    public static function filterMentions($data)
    {
        $commentPattern = '/(^|\s)@(\w*[a-zA-Z_]+\w*)/';
        $output = preg_replace($commentPattern, ' <a href="http://twitter.com/search?q=%40\2">@\2</a>', $data);

        return $output;
    }

    /**
     * A custom filter for creating links for hash tags.
     *
     * @param string $data - The string being filtered
     *
     * @return string - The filtered $data
     */
    public static function filterHashtags($data)
    {
        $commentPattern = '/(^|\s)#(\w*[a-zA-Z_]+\w*)/';
        $output = preg_replace($commentPattern, ' <a href="http://twitter.com/search?q=%23\2">#\2</a>', $data);

        return $output;
    }

    /**
     * A custom filter for converting certain BB code into appropriate HTML entities.
     *
     * @param string $data - The string being filtered
     *
     * @return string - The filtered $data
     */
    public static function filterBBCode($data)
    {
        $codes = [
            '#\[b\](.*?)\[/b\]#is'               => '<b>$1</b>',
            '#\[p\](.*?)\[/p\]#is'               => '<p>$1</p>',
            '#\[i\](.*?)\[/i\]#is'               => '<i>$1</i>',
            '#\[u\](.*?)\[/u\]#is'               => '<u>$1</u>',
            '#\[s\](.*?)\[/s\]#is'               => '<del>$1</del>',
            '#\[code\](.*?)\[/code\]#is'         => '<code>$1</code>',
            '#\[color=(.*?)\](.*?)\[/color\]#is' => "<font color='$1'>$2</font>",
            '#\[img\](.*?)\[/img\]#is'           => "<img src='$1'>",
            '#\(c\)#is'                          => '&#10004;',
            '#\(x\)#is'                          => '&#10006;',
            '#\(!\)#is'                          => '&#10069;',
            '#\(\?\)#is'                         => '&#10068;',
            '#\[list\](.*?)\[/list\]#is'         => '<ul>$1</ul>',
            '#\(\.\)(.*)$#m'                     => '<li>$1</li>',
            '#\[url=(.*?)\](.*?)\[/url\]#is'     => "<a href='$1'>$2</a>",
            '#\[quote=(.*?)\](.*?)\[/quote\]#is' => "<blockquote cite='$1'>$2</blockquote>",
        ];
        foreach ($codes as $reg => $replace) {
            $data = preg_replace($reg, $replace, $data);
        }

        return $data;
    }

    /**
     * A custom filter for removing annotated sections and code comments.
     *
     * @param string $data - The string being filtered
     *
     * @return string - The filtered $data
     */
    public static function filterComments($data)
    {
        $commentPattern = ['#/\*.*?\*/#s', '#(?<!:)//.*#'];
        $output = preg_replace($commentPattern, null, $data);

        return $output;
    }

    /**
     * The loop function for the template engine's {loop}{/loop} tag.
     *
     * @param string $template The string being checked for a loop
     * @param array  $data     the data being looped through
     *
     * @return string the filtered and completed LOOP
     */
    public static function buildLoop($template, $data = null)
    {
        $header = null;
        $footer = null;
        $final = null;
        $loopAlternative = null;

        $loop = '#.*{LOOP}(.*?){/LOOP}.*#is';
        $loopTemplate = preg_replace($loop, '$1', $template);
        if ($loopTemplate != $template) {
            //Separate off the header if it exists.
            $header = trim(preg_replace('#^(.*)?{LOOP}.*$#is', '$1', $template));
            if ($header === $template) {
                $header = null;
            }

            //Separate off the footer if it exists.
            $footer = trim(preg_replace('#^.*?{/LOOP}(.*)$#is', '$1', $template));
            if ($footer === $template) {
                $footer = null;
            }

            if (!empty($footer)) {
                //Separate off the alternative to the loop if it exists.
                $alt = '#{ALT}(.*?){/ALT}#is';
                $loopAlternative = trim(preg_replace($alt, '$1', $footer));
                if ($loopAlternative === $footer) {
                    $loopAlternative = null;
                } else {
                    $footer = trim(preg_replace('#^.*?{/ALT}(.*)$#is', '$1', $footer));
                }
            }
        }

        // Paginate
        if (strpos($template, '{PAGINATION}') !== false) {
            Template::paginate();
        }

        if (!empty($data)) {
            //iterate through the data as instances.
            foreach ($data as $instance) {
                $x = 0;
                //reset the template for every iteration of $data.
                $modifiedTemplate = $loopTemplate;

                if (!is_object($instance)) {
                    $instance = $data;
                    $end = 1;
                }
                //loop the template as many times as we have data for.
                foreach ($instance as $key => $value) {
                    if (!is_object($value)) {
                        $tagPattern = "~{($key)}~i";
                        if (is_array($value)) {
                            $value = '';
                        }
                        $modifiedTemplate = preg_replace($tagPattern, $value, $modifiedTemplate);
                    }
                }

                //since this loop may have a header, and/or footer, we have to define the final output of the loop.
                $final .= $modifiedTemplate;

                if ($x === 0) {
                    $singlePattern = '#{SINGLE}(.*?){/SINGLE}#is';
                    //If there is a {SINGLE}{/SINGLE} tag, we will replace it on the first iteration.
                    $final = preg_replace($singlePattern, '$1', $final);

                    //Same practice, but for the entry template.
                    $loopTemplate = preg_replace($singlePattern, null, $loopTemplate);
                    ++$x;
                }

                //Since $data is only for a single data set, we break the loop.
                if (isset($end)) {
                    unset($end);
                    $output = $header . $final . $footer;
                    break;
                }
            }
            $output = $header . $final . $footer;
        } else {
            if (!empty($loopAlternative)) {
                $output = $header . $loopAlternative;
            } else {
                $output = $header . $loopTemplate . $footer;
            }
        }
        return $output;
    }

    /**
     * This is the main function of the template engine.
     * this function parses the given view and replaces
     * all of the necessary components with their processed
     * counterparts.
     *
     * @param  string       $template   - The html that needs to be parsed.
     * @param  array|object $data       - An associative array or object that will
     *                                  be used as components for the provided html.
     *
     * @return string - The fully parsed html output.
     */
    public static function parse($template, $data = null)
    {
        //remove all comments from the source.
        $template = self::filterComments($template);

        //Run through our full list of generated filters.
        $template = self::filters($template);

        //Check for a {LOOP}{/LOOP} tag.
        $template = self::buildLoop($template, $data);

        //Run through our full list of generated keys.
        foreach (self::$values as $key => $value) {
            $tagPattern = "~{($key)}~i";
            $template = preg_replace($tagPattern, $value, $template);
        }

        if (strpos($template, '{OPTION=') !== false) {
            foreach (self::$options as $key => $value) {
                $template = preg_replace($key, $value, $template);
            }
            $template = preg_replace('#\{OPTION\=(.*?)\}#is', '', $template);
        }

        //Convert any dates into preferred Date/Time format. User preference will be applied her in the future.
        $dtc = '#{DTC(.*?)}(.*?){/DTC}#is';
        $template = preg_replace_callback(
            $dtc,
            function ($data) {
                if (stripos($data[1], 'date')) {
                    $dateFormat = self::$activePrefs->dateFormat;
                } elseif (stripos($data[1], 'time')) {
                    $dateFormat = self::$activePrefs->timeFormat;
                } else {
                    $dateFormat = self::$activePrefs->dateFormat . ' ' . self::$activePrefs->timeFormat;
                }
                $time = $data[2] + 0;
                $dt = new DateTime(self::$activePrefs->timezone);
                $dt->setTimestamp($time);
                return $dt->format($dateFormat);
            },
            $template
        );
        $template = self::filterHashtags($template);
        $template = self::filterBBCode($template);
        $template = self::filterMentions($template);
        $template = self::filterFormComponents($template);
        return $template;
    }
}
