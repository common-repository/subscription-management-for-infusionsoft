<?php

class SubscriptionManagementAdmin
{
    const NO_CATEGORY_KEY = '*';

    const MESSAGE_CREDENTIALS_VERIFIED = 'Infusionsoft API connection verified.';

    public static function init()
    {
        add_action('admin_menu', array('SubscriptionManagementAdmin', 'adminMenu'));

	    wp_enqueue_script('jquery');
	    wp_enqueue_script(
	            'check-all',
		        plugin_dir_url( __FILE__ ) . 'js/check-all.js',
		        array('jquery'),
                SUBSCRIPTIONMANAGEMENT__VERSION,
            true
        );
    }

    public static function adminMenu()
    {
        add_options_page(
            'Infusionsoft Subscription Management Settings',
            'Subscription Mgmt',
            'manage_options',
            'subscription_management_settings',
            array('SubscriptionManagementAdmin', 'settingsPage')
        );
    }


    public static function settingsPage()
    {
        $disable_tag_cache = !empty($_REQUEST['refresh_tags']);
        self::processSettingsForm();
        $api = SubscriptionManagement::connect();
        $account_tags = self::fetchAccountTags($api, $disable_tag_cache);
        $active_tags = self::processActiveTagInputs($account_tags);
        self::renderSettingsPage($api, $account_tags, $active_tags);
    }

    protected static function processSettingsForm()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            return false;

        /* Store simple options. */
        if (isset($_POST['sm_appname']))
            update_option('subscription_isdk_app_name', $_POST['sm_appname']);
        if (isset($_POST['sm_apikey']))
            update_option('subscription_isdk_api_key', $_POST['sm_apikey']);
        if (isset($_POST['sm_default_tag_id']))
	    update_option('subscription_default_tag_id', trim($_POST['sm_default_tag_id']));

		    update_option('only_show_active_tags', !empty($_POST['sm_show_active']));
        if (isset($_POST['sm_update_tag_id']))
            update_option('subscription_update_tag_id', trim($_POST['sm_update_tag_id']));
        update_option('subscription_recaptcha_enable', !empty($_POST['sm_recaptcha_enable']));
        if (isset($_POST['sm_recaptcha_site_key']))
            update_option('subscription_recaptcha_site_key', trim($_POST['sm_recaptcha_site_key']));
        if (isset($_POST['sm_recaptcha_secret_key']))
            update_option('subscription_recaptcha_secret_key', trim($_POST['sm_recaptcha_secret_key']));

        if (isset($_POST['sm_update_redirect_url'])) {
            $redirect_url = trim($_POST['sm_update_redirect_url']);
            if ($redirect_url)
                $redirect_url = filter_var($redirect_url, FILTER_SANITIZE_URL);
            if ($redirect_url !== false)
                update_option('subscription_update_redirect_url', $redirect_url);
        }
        if (isset($_POST['sm_unsubscribe_redirect_url'])) {
            $redirect_url = trim($_POST['sm_unsubscribe_redirect_url']);
            if ($redirect_url)
                $redirect_url = filter_var($redirect_url, FILTER_SANITIZE_URL);
            if ($redirect_url !== false)
                update_option('subscription_unsubscribe_redirect_url', $redirect_url);
        }

        return true;
    }

    /* Build the new active tag set from the form inputs. */
    protected static function processActiveTagInputs($account_tags)
    {
        /* Don't update the active tags if the form didn't contain tag inputs. */
        if ($account_tags ===  null || empty($_POST['sm_update_active_tags']))
            return SubscriptionManagement::loadActiveTagSet();

        $new_active_tags = array();
        foreach ($account_tags as $category) {
            foreach ($category as $tag_id => $tag_name) {
                $input_name = 'subscription_tag_' . $tag_id;
                if (!empty($_POST[$input_name]))
                    $new_active_tags[$tag_id] = $tag_name;
            }
        }

        update_option('subscription_tag_ids', $new_active_tags);

        return $new_active_tags;
    }

    protected static function fetchAccountTags($api, $no_cache = false)
    {
        if ($api === null)
            return null;

        /* Maybe satisfy the request from cache. */
        if (!$no_cache) {
            $cached_tags = get_option('subscription_cached_account_tags');
            if (!empty($cached_tags) && is_array($cached_tags))
                return $cached_tags;
        }

        /* Fetch all tags and tag categories.  */
        $data_tags = $api->dsQuery('ContactGroup', 10000, 0, array('Id' => '%'), array('Id', 'GroupCategoryId', 'GroupName'));
        if (!is_array($data_tags))
            return null;

        $data_categories = $api->dsQuery('ContactGroupCategory', 10000, 0, array('Id' => '%'), array('Id', 'CategoryName'));
        if (!is_array($data_categories))
            return null;

        /* Index the categories by ID. */
        $categories_by_id = array();
        foreach ($data_categories as $category) {
            $cat_id = (int)$category['Id'];
            $categories_by_id[$cat_id] = $category['CategoryName'];
        }

        /* Index the tags by category. */
        $tags_by_category = array();
        foreach ($data_tags as $tag) {
            $cat_id = (int)$tag['GroupCategoryId'];
            $cat_name = !empty($categories_by_id[$cat_id]) ? $categories_by_id[$cat_id] : self::NO_CATEGORY_KEY;
            if (!isset($tags_by_category[$cat_name]))
                $tags_by_category[$cat_name] = array();
            $tags_by_category[$cat_name][(int)$tag['Id']] = $tag['GroupName'];
        }

        /* Order the categories alphabetically. */
        ksort($tags_by_category);

        /* Update the cache. */
        update_option('subscription_cached_account_tags', $tags_by_category);

        return $tags_by_category;
    }

    protected static function renderSettingsPrologue($update_message)
    {
        ?>
        <div class="wrap">
        <h1 class=""><span>Infusionsoft Subscription Management Settings</span></h1>
        <?php if ($update_message): ?>
        <div class="updated settings-error notice is-dismissible"><p><strong><?php echo $update_message; ?></strong></p></div>
        <?php endif; ?>
        <p><a href="https://pirateandfox.com/wp-content/uploads/2018/07/Subscription-Management-for-Infusionsoft.pdf" target="_blank">Read the full instructions</a></p>

        <div class="inside">
        <form action="" method="post">
        <table class="form-table">
        <?php
    }

    protected static function renderSettingEpilogue()
    {
        ?>
        </table>
        <p><input class="button button-primary button-large" type="submit" value="Save Changes"></p>
        </form>
        </div>
        </div>
        <?php
    }

    protected static function renderCredentialsRows($option_isdk_app_name, $option_isdk_api_key)
    {
        ?>
        <tr>
            <th scope="row"><strong>iSDK App Name:</strong></th>
            <td>
                <input type="text" class="text" name="sm_appname" value="<?php echo $option_isdk_app_name; ?>">.infusionsoft.com
                <br>
                <span class="description">Your app name is the URL you use to access Infusionsoft.</span>
            </td>
        </tr>
        <tr>
            <th scope="row"><strong>iSDK API Key:</strong></th>
            <td><input type="text" class="text" size="45" name="sm_apikey" value="<?php echo $option_isdk_api_key ?>"></td>
        </tr>
        <?php
    }

    protected static function renderFormConfigRows()
    {
        $option_unsubscribe_redirect_url = get_option('subscription_unsubscribe_redirect_url');
        $option_update_redirect_url = get_option('subscription_update_redirect_url');

        ?>
        <tr>
            <th scope="row">
                <strong>Unsubscribe Redirect URL:</strong>
            </th>
            <td>
                <input type="text" placeholder="http://example.org/unsubscribed/" class="text" name="sm_unsubscribe_redirect_url" value="<?php echo $option_unsubscribe_redirect_url; ?>" size="100">
                <p class="description">Optional URL to redirect the user to when they unsubscribe.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <strong>Update Redirect URL:</strong>
            </th>
            <td>
                <input type="text" placeholder="http://example.org/subscription-update/"class="text" name="sm_update_redirect_url" value="<?php echo $option_update_redirect_url; ?>" size="100">
                <p class="description">Optional URL to redirect the user to when they update subscriptions, but don't unsubscribe entirely.</p>
            </td>
        </tr>
        <?php
    }

    protected static function renderTagsRows($account_tags, $active_tags)
    {
	    $show_active = get_option('only_show_active_tags');
        $default_tag_id = get_option('subscription_default_tag_id');
        $update_tag_id = get_option('subscription_update_tag_id');
        $recaptcha_enable = get_option('subscription_recaptcha_enable');
        $recaptcha_site_key = get_option('subscription_recaptcha_site_key');
        $recaptcha_secret_key = get_option('subscription_recaptcha_secret_key');

        /* Unsubscription tag row. */
        echo '<tr>';
        echo '<th scope="row">';
        echo '<strong>Unsubscription Tag ID:</strong>';
        echo '</th>';
        echo '<td>';
        echo sprintf('<input type="text" name="%s" value="%s">', 'sm_default_tag_id', $default_tag_id);
        echo '<p class="description">If set, this tag is applied when a user unsubscribes from all email lists and is reapplied if the user resubscribes.</p>';
        echo '</td>';
        echo '</tr>';

        /* Update tag row. */
        echo '<tr>';
        echo '<th scope="row">';
        echo '<strong>Update Trigger Tag ID:</strong>';
        echo '</th>';
        echo '<td>';
        echo sprintf('<input type="text" name="%s" value="%s">', 'sm_update_tag_id', $update_tag_id);
        echo '<p class="description">If set, this tag is applied every time a user updates their preferences.</p>';
        echo '</td>';
        echo '</tr>';

	    /* Active Tags Only option row.. */
	    echo '<tr>';
	    echo '<th scope="row">';
	    echo '<strong>Only Show Active Tags?</strong>';
	    echo '</th>';
	    echo '<td>';
	    echo sprintf('<input id="sm_show_active" type="checkbox" name="%s" %s>', 'sm_show_active', $show_active ? 'checked' : '');
	    echo '<label for="sm_show_active">Show Active Tags</label>';
	    echo '<p class="description">If this is checked, a user will only see selected tags that are applied to their record currently.</p>';
	    echo '</td>';
	    echo '</tr>';

        /* reCAPTCHA option row.. */
        echo '<tr>';
        echo '<th scope="row">';
        echo '<strong>reCAPTCHA Protection:</strong>';
        echo '</th>';
        echo '<td>';
        echo sprintf('<input id="sm_recaptcha_check" type="checkbox" name="%s" %s>', 'sm_recaptcha_enable', $recaptcha_enable ? 'checked' : '');
        echo '<label for="sm_recaptcha_check">Use reCAPTCHA</label>';
        echo '<p class="description">Use a reCAPTCHA field in parts of the form vulnerable to misuse through automated submission.</p>';
        echo '</td>';
        echo '</tr>';

        /* reCAPTCHA API key rows. */
        echo '<tr>';
        echo '<th scope="row">';
        echo '<strong>reCAPTCHA V2 Site Key:</strong>';
        echo '</th>';
        echo '<td>';
        echo sprintf('<input type="text" name="%s" value="%s" size="60">', 'sm_recaptcha_site_key', $recaptcha_site_key);
        echo '<p class="description">Your reCAPTCHA V2 site (public) key. Required if the reCAPTCHA option is enabled above.</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">';
        echo '<strong>reCAPTCHA V2 Secret Key:</strong>';
        echo '</th>';
        echo '<td>';
        echo sprintf('<input type="text" name="%s" value="%s" size="60">', 'sm_recaptcha_secret_key', $recaptcha_secret_key);
        echo '<p class="description">Your reCAPTCHA V2 secret key. Required if the reCAPTCHA option is enabled above.</p>';
        echo '</td>';
        echo '</tr>';


        /* Subscription tag rows. */
        echo '<tr>';
        echo '<th scope="row">';
        echo '<strong>Subscription Tags:</strong>';
        echo '<p class="description">Choose the tags you\'d like to appear on the subscription update form.</p>';
        echo '<p class="description"><a href="?page=subscription_management_settings&refresh_tags=1">Refresh Account Tags</a></p>';
        echo '</th>';

        echo '<td>';
	    echo '<input type="checkbox" id="checkall" class="chk_all" label="check all"  />';
	    echo '<label for="checkall">Check / UnCheck All</label>';
        foreach ($account_tags as $category_name => $tags) {
            if ($category_name !== self::NO_CATEGORY_KEY)
                echo '<h3>' . $category_name . '</h3>';
            echo '<ul>';
            foreach ($tags as $tag_id => $tag_name) {
                $input_name = 'subscription_tag_' . $tag_id;
                $input_checked = !empty($active_tags[$tag_id]) ? 'checked' : '';
                $input_label = "{$tag_name} ({$tag_id})";
                echo '<li>';
                echo sprintf('<input class="chk_boxes" type="checkbox" id="%s" name="%s" value="1" %s>', $input_name, $input_name, $input_checked);
                echo sprintf('<label for="%s">%s</label><br>', $input_name, $input_label);
                echo '</li>';
            }
            echo '</ul>';
        }

        /* This field tells the form processor to expect tag inputs. */
        echo '<input type="hidden" name="sm_update_active_tags" value="1" />';

        echo '</td>';
        echo '</tr>';
    }

    protected static function renderSettingsPage($api, $account_tags, $active_tags)
    {
        $option_isdk_app_name = get_option('subscription_isdk_app_name');
        $option_isdk_api_key = get_option('subscription_isdk_api_key');

        $update_message = ($api !== null) ? self::MESSAGE_CREDENTIALS_VERIFIED : null;

        self::renderSettingsPrologue($update_message);
        self::renderCredentialsRows($option_isdk_app_name, $option_isdk_api_key);
        self::renderFormConfigRows();
        if ($account_tags)
            self::renderTagsRows($account_tags, $active_tags);
        self::renderSettingEpilogue();
    }
}
