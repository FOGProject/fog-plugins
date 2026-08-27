<?php
/**
 * Example background task: the plugin's only piece of work with no request
 * behind it.
 *
 * Everything else in this plugin runs because a browser asked for it -- the
 * page, the REST resource, the hooks. A task runs on a schedule instead,
 * driven by the FOGPluginRunner daemon, which is what you want for polling,
 * reconciling, expiring or retrying (ADR 0010).
 *
 * Three rules the runner imposes, all of which shape what a task may do:
 *
 * 1. It runs as the WEB USER, not root. Installing a plugin grants exactly
 *    what installing a plugin already granted. No mounts, no image trees, no
 *    device nodes.
 * 2. Every plugin's tasks share one process and run one at a time. A task
 *    that blocks holds up every other plugin's, so bound your network and
 *    database calls. (Nothing outside plugin tasks is affected -- this daemon
 *    does nothing else.)
 * 3. run() must be idempotent. $interval is a floor, not a promise: the
 *    runner keeps next-run times in memory, so a service restart makes every
 *    task immediately due, and a run that throws is retried next cycle.
 *
 * NAMING, and this one bites: the class name must match the filename minus
 * .task.php, and it shares ONE global namespace with every other class in
 * FOG -- core models included. A file named host.task.php would collide with
 * the core Host model. Prefix with your plugin's name, as here.
 *
 * PHP version 5
 *
 * @category HelloWorldHeartbeat
 * @package  FOGProject
 * @author   FOG Project <info@fogproject.org>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
/**
 * Counts this plugin's rows on a schedule and writes the number to the log.
 *
 * @category HelloWorldHeartbeat
 * @package  FOGProject
 * @author   FOG Project <info@fogproject.org>
 * @license  http://opensource.org/licenses/gpl-3.0 GPLv3
 * @link     https://fogproject.org
 */
class HelloWorldHeartbeat extends \FOG\Base\PluginTask
{
    /**
     * Shown in the service log instead of the class name.
     *
     * @var string
     */
    public $name = 'Hello World heartbeat';
    /**
     * What this task is for.
     *
     * @var string
     */
    public $description = 'Counts Hello World entries and logs the total.';
    /**
     * Seconds between runs.
     *
     * Clamped by the runner to a 60-second floor, and rounded up in practice
     * to PLUGINRUNNERSLEEPTIME -- that setting is the scheduling granularity,
     * so asking for less than it buys nothing.
     *
     * @var int
     */
    public $interval = 900;
    /**
     * Set false to ship a task without running it. The plugin's active state
     * is the admin's switch; this one is yours.
     *
     * @var bool
     */
    public $active = true;
    /**
     * Does the work.
     *
     * Deliberately read-only and cheap: an example that deleted or rewrote
     * rows would be copied into plugins that then did so on a schedule
     * nobody remembered configuring.
     *
     * Note what is NOT here: no try/catch. The runner catches Throwable
     * around this call and logs the failure against the plugin and task, so
     * letting a real error propagate produces a better log line than
     * swallowing it would. Catch only what you can genuinely handle.
     *
     * @return void
     */
    public function run()
    {
        // The same ORM the page and the REST resource use. getCount() asks
        // the database for the number rather than fetching every row to count
        // them in PHP -- worth caring about in a task, which may run against
        // a table the UI never paginates.
        //
        // getCount(), not count(): count() returns void and stashes the
        // result for getData() to encode. getCount() is the wrapper that
        // hands back an int.
        $total = \FOG\Router\Route::getCount('helloworld');
        $this->logLine(
            sprintf(
                '%s: %d',
                _('Hello World entries'),
                $total
            )
        );
    }
}
