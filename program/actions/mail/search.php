<?php

/**
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 |                                                                       |
 | Copyright (C) The Roundcube Dev Team                                  |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Mail messages search action                                         |
 +-----------------------------------------------------------------------+
 | Author: Benjamin Smith <defitro@gmail.com>                            |
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+
*/

class rcmail_action_mail_search extends rcmail_action_mail_index
{
    protected static $mode = self::MODE_AJAX;

    /**
     * Request handler.
     *
     * @param array $args Arguments from the previous step(s)
     */
    public function run($args = [])
    {
        $rcmail = rcmail::get_instance();

        @set_time_limit(170);  // extend default max_execution_time to ~3 minutes

        // reset list_page and old search results
        $rcmail->storage->set_page(1);
        $rcmail->storage->set_search_set(NULL);
        $_SESSION['page'] = 1;

        // using encodeURI with javascript "should" give us
        // a correctly encoded query string
        $imap_charset = RCUBE_CHARSET;

        // get search string
        $str      = rcube_utils::get_input_value('_q', rcube_utils::INPUT_GET, true);
        $mbox     = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_GET, true);
        $filter   = rcube_utils::get_input_value('_filter', rcube_utils::INPUT_GET);
        $headers  = rcube_utils::get_input_value('_headers', rcube_utils::INPUT_GET);
        $scope    = rcube_utils::get_input_value('_scope', rcube_utils::INPUT_GET);
        $interval = rcube_utils::get_input_value('_interval', rcube_utils::INPUT_GET);
        $continue = rcube_utils::get_input_value('_continue', rcube_utils::INPUT_GET);

        $filter         = trim($filter);
        $search_request = md5($mbox . $scope . $interval . $filter . $str);

        // Parse input
        list($subject, $srch) = self::search_input($str, $headers, $scope);

        $search = isset($srch) ? trim($srch) : trim($str);

        // add list filter string
        $search_str = $filter && $filter != 'ALL' ? $filter : '';

        if ($search_interval = self::search_interval_criteria($interval)) {
            $search_str .= ' ' . $search_interval;
        }

        if (!empty($subject)) {
            $search_str .= str_repeat(' OR', count($subject)-1);
            foreach ($subject as $sub) {
                $search_str .= ' ' . $sub . ' ' . rcube_imap_generic::escape($search);
            }
        }

        $search_str  = trim($search_str);
        $sort_column = self::sort_column();
        $sort_order  = self::sort_order();

        // set message set for already stored (but incomplete) search request
        if (!empty($continue) && isset($_SESSION['search']) && $_SESSION['search_request'] == $continue) {
            $rcmail->storage->set_search_set($_SESSION['search']);
            $search_str = $_SESSION['search'][0];
        }

        // execute IMAP search
        if ($search_str) {
            // search all, current or subfolders folders
            if ($scope == 'all') {
                $mboxes = $rcmail->storage->list_folders_subscribed('', '*', 'mail', null, true);
                // we want natural alphabetic sorting of folders in the result set
                natcasesort($mboxes);
            }
            else if ($scope == 'sub') {
                $delim  = $rcmail->storage->get_hierarchy_delimiter();
                $mboxes = $rcmail->storage->list_folders_subscribed($mbox . $delim, '*', 'mail');
                array_unshift($mboxes, $mbox);
            }

            if ($scope != 'all') {
                // Remember current folder, it can change in meantime (plugins)
                // but we need it to e.g. recognize Sent folder to handle From/To column later
                $rcmail->output->set_env('mailbox', $mbox);
            }

            $result = $rcmail->storage->search($mboxes, $search_str, $imap_charset, $sort_column);
        }

        // save search results in session
        if (!is_array($_SESSION['search'])) {
            $_SESSION['search'] = [];
        }

        if ($search_str) {
            $_SESSION['search'] = $rcmail->storage->get_search_set();
            $_SESSION['last_text_search'] = $str;
        }

        $_SESSION['search_request']  = $search_request;
        $_SESSION['search_scope']    = $scope;
        $_SESSION['search_interval'] = $interval;
        $_SESSION['search_filter']   = $filter;

        // Get the headers
        if (!$result->incomplete) {
            $result_h = $rcmail->storage->list_messages($mbox, 1, $sort_column, $sort_order);
        }

        // Make sure we got the headers
        if (!empty($result_h)) {
            $count = $rcmail->storage->count($mbox, $rcmail->storage->get_threading() ? 'THREADS' : 'ALL');

            self::js_message_list($result_h, false);

            if ($search_str) {
                $all_count = $rcmail->storage->count(null, 'ALL');
                $rcmail->output->show_message('searchsuccessful', 'confirmation', ['nr' => $all_count]);
            }

            // remember last HIGHESTMODSEQ value (if supported)
            // we need it for flag updates in check-recent
            if ($mbox !== null) {
                $data = $rcmail->storage->folder_data($mbox);
                if (!empty($data['HIGHESTMODSEQ'])) {
                    $_SESSION['list_mod_seq'] = $data['HIGHESTMODSEQ'];
                }
            }
        }
        // handle IMAP errors (e.g. #1486905)
        else if ($err_code = $rcmail->storage->get_error_code()) {
            $count = 0;
            self::display_server_error();
        }
        // advice the client to re-send the (cross-folder) search request
        else if ($result->incomplete) {
            $count = 0;  // keep UI locked
            $rcmail->output->command('continue_search', $search_request);
        }
        else {
            $count = 0;

            $rcmail->output->show_message('searchnomatch', 'notice');
            $rcmail->output->set_env('multifolder_listing', (bool) $result->multi);

            if ($result->multi && $scope == 'all') {
                $rcmail->output->command('select_folder', '');
            }
        }

        // update message count display
        $rcmail->output->set_env('search_request', $search_str ? $search_request : '');
        $rcmail->output->set_env('search_filter', $_SESSION['search_filter']);
        $rcmail->output->set_env('messagecount', $count);
        $rcmail->output->set_env('pagecount', ceil($count / $rcmail->storage->get_pagesize()));
        $rcmail->output->set_env('exists', $mbox === null ? 0 : $rcmail->storage->count($mbox, 'EXISTS'));
        $rcmail->output->command('set_rowcount', self::get_messagecount_text($count, 1), $mbox);

        self::list_pagetitle();

        // update unseen messages count
        if (empty($search_str)) {
            self::send_unread_count($mbox, false, empty($result_h) ? 0 : null);
        }

        if (!$result->incomplete) {
            $rcmail->output->command('set_quota', self::quota_content(null, $result->multi ? 'INBOX' : $mbox));
        }

        $rcmail->output->send();
    }

    /**
     * Creates BEFORE/SINCE search criteria from the specified interval
     * Interval can be: 1W, 1M, 1Y, -1W, -1M, -1Y
     */
    public static function search_interval_criteria($interval)
    {
        if (empty($interval)) {
            return;
        }

        if ($interval[0] == '-') {
            $search   = 'BEFORE';
            $interval = substr($interval, 1);
        }
        else {
            $search = 'SINCE';
        }

        $date     = new DateTime('now');
        $interval = new DateInterval('P' . $interval);

        $date->sub($interval);

        return $search . ' ' . $date->format('j-M-Y');
    }

    public static function search_input($str, $headers, $scope)
    {
        $rcmail  = rcmail::get_instance();
        $subject = [];
        $srch    = null;

        // Check the search string for type of search
        if (preg_match("/^from:.*/i", $str)) {
            list(, $srch) = explode(":", $str);
            $subject['from'] = "HEADER FROM";
        }
        else if (preg_match("/^to:.*/i", $str)) {
            list(, $srch) = explode(":", $str);
            $subject['to'] = "HEADER TO";
        }
        else if (preg_match("/^cc:.*/i", $str)) {
            list(, $srch) = explode(":", $str);
            $subject['cc'] = "HEADER CC";
        }
        else if (preg_match("/^bcc:.*/i", $str)) {
            list(, $srch) = explode(":", $str);
            $subject['bcc'] = "HEADER BCC";
        }
        else if (preg_match("/^subject:.*/i", $str)) {
            list(, $srch) = explode(":", $str);
            $subject['subject'] = "HEADER SUBJECT";
        }
        else if (preg_match("/^body:.*/i", $str)) {
            list(, $srch) = explode(":", $str);
            $subject['body'] = "BODY";
        }
        else if (strlen(trim($str))) {
            if ($headers) {
                foreach (explode(',', $headers) as $header) {
                    if ($header == 'text') {
                        // #1488208: get rid of other headers when searching by "TEXT"
                        $subject = ['text' => 'TEXT'];
                        break;
                    }
                    else {
                        $subject[$header] = ($header != 'body' ? 'HEADER ' : '') . strtoupper($header);
                    }
                }

                // save search modifiers for the current folder to user prefs
                $mkey               = $scope == 'all' ? '*' : $mbox;
                $search_mods        = self::search_mods();
                $search_mods[$mkey] = array_fill_keys(array_keys($subject), 1);

                $rcmail->user->save_prefs(['search_mods' => $search_mods]);
            }
            else {
                // search in subject by default
                $subject['subject'] = 'HEADER SUBJECT';
            }
        }

        return [$subject, $srch];
    }
}
