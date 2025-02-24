<?php

namespace OCA\OIDCLogin\Service;

use OCP\IL10N;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\ISession;
use OC\User\LoginException;
use OCA\OIDCLogin\Provider\OpenIDConnectClient;

class LoginService
{
    /** @var string */
    private $appName;
    /** @var IConfig */
    private $config;
    /** @var IUserManager */
    private $userManager;
    /** @var IGroupManager */
    private $groupManager;
    /** @var ISession */
    private $session;
    /** @var IL10N */
    private $l;
    /** @var \OCA\Files_External\Service\GlobalStoragesService */
    private $storagesService;


    public function __construct(
        $appName,
        IConfig $config,
        IUserManager $userManager,
        IGroupManager $groupManager,
        ISession $session,
        IL10N $l,
        $storagesService
    ) {
        $this->appName = $appName;
        $this->config = $config;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
        $this->session = $session;
        $this->l = $l;
        $this->storagesService = $storagesService;
    }

    public function createOIDCClient($callbackUrl = '') {
        $oidc = new OpenIDConnectClient(
            $this->session,
            $this->config,
            $this->appName,
        );
        $oidc->setRedirectURL($callbackUrl);

        // set TLS development mode
        $oidc->setVerifyHost($this->config->getSystemValue('oidc_login_tls_verify', true));
        $oidc->setVerifyPeer($this->config->getSystemValue('oidc_login_tls_verify', true));

        // Set OpenID Connect Scope
        $scope = $this->config->getSystemValue('oidc_login_scope', 'openid');
        $oidc->addScope($scope);
        return $oidc;
    }

    public function login($profile)
    {
        // Get attributes
        $confattr = $this->config->getSystemValue('oidc_login_attributes', array());
        $defattr = array(
            'id' => 'sub',
            'name' => 'name',
            'mail' => 'email',
            'quota' => 'ownCloudQuota',
            'home' => 'homeDirectory',
            'ldap_uid' => 'uid',
            'groups' => 'ownCloudGroups',
        );
        $attr = array_merge($defattr, $confattr);

        // Flatten the profile array
        $profile = $this->flatten($profile);

        // Get UID
        $uid = $profile[$attr['id']];

        // Ensure the LDAP user exists if we are proxying for LDAP
        if ($this->config->getSystemValue('oidc_login_proxy_ldap', false)) {
            // Get LDAP uid
            $ldapUid = $profile[$attr['ldap_uid']];
            if (empty($ldapUid)) {
                throw new LoginException($this->l->t('No LDAP UID found in OpenID response'));
            }

            // Get the LDAP user backend
            $ldap = NULL;
            foreach ($this->userManager->getBackends() as $backend) {
                if ($backend->getBackendName() == $this->config->getSystemValue('oidc_login_ldap_backend', "LDAP")) {
                    $ldap = $backend;
                }
            }

            // Check if backend found
            if ($ldap == NULL) {
                throw new LoginException($this->l->t('No LDAP user backend found!'));
            }

            // Get LDAP Access object
            $access = $ldap->getLDAPAccess($ldapUid);

            // Get the DN
            $dns = $access->fetchUsersByLoginName($ldapUid);
            if (empty($dns)) {
                throw new LoginException($this->l->t('Error getting DN for LDAP user'));
            }
            $dn = $dns[0];

            // Store the user
            $ldapUser = $access->userManager->get($dn);
            if ($ldapUser == NULL) {
                throw new LoginException($this->l->t('Error getting user from LDAP'));
            }

            // Method no longer exists on NC 20+
            if (method_exists($ldapUser, 'update')) {
                $ldapUser->update();
            }

            // Update the email address (#84)
            if (method_exists($ldapUser, 'updateEmail')) {
                $ldapUser->updateEmail();
            }

            // Force a UID for existing users with a different
            // user ID in nextcloud than in LDAP
            $uid = $ldap->dn2UserName($dn) ?: $uid;
        }

        // Check UID
        if (empty($uid)) {
            throw new LoginException($this->l->t('Can not get identifier from provider'));
        }

        // Check max length of uid
        if (strlen($uid) > 64) {
            $uid = md5($uid);
        }

        // Get user with fallback
        $user = $this->userManager->get($uid);
        $userPassword = '';

        // Create user if not existing
        if (null === $user) {
            if ($this->config->getSystemValue('oidc_login_disable_registration', true)) {
                throw new LoginException($this->l->t('Auto creating new users is disabled'));
            }

            $userPassword = substr(base64_encode(random_bytes(64)), 0, 30);
            $user = $this->userManager->createUser($uid, $userPassword);
        }

        // Get base data directory
        $datadir = $this->config->getSystemValue('datadirectory');

        // Set home directory unless proxying for LDAP
        if (!$this->config->getSystemValue('oidc_login_proxy_ldap', false) && 
            array_key_exists($attr['home'], $profile)) {

            // Get intended home directory
            $home = $profile[$attr['home']];

            if($this->config->getSystemValue('oidc_login_use_external_storage', false)) {
                // Check if the files external app is enabled and injected
                if ($this->storagesService === null) {
                    throw new LoginException($this->l->t('files_external app must be enabled to use oidc_login_use_external_storage'));
                }

                // Check if the user already has matching storage on their root
                $storages = array_filter($this->storagesService->getStorages(), function ($storage) use ($uid) {
                    return in_array($uid, $storage->getApplicableUsers()) && // User must own the storage
                        $storage->getMountPoint() == "/" && // It must be mounted as root
                        $storage->getBackend()->getIdentifier() == 'local' && // It must be type local
                        count($storage->getApplicableUsers() == 1); // It can't be shared with other users
                });

                if(!empty($storages)) {
                    // User had storage on their / so make sure it's the correct folder
                    $storage = array_values($storages)[0];
                    $options = $storage->getBackendOptions();

                    if($options['datadir'] != $home) {
                        $options['datadir'] = $home;
                        $storage->setBackendOptions($options);
                        $this->storagesService->updateStorage($storage);
                    }
                } else {
                    // User didnt have any matching storage on their root, so make one
                    $storage = $this->storagesService->createStorage('/', 'local', 'null::null', array(
                        'datadir' => $home
                    ), array(
                        'enable_sharing' => true
                    ));
                    $storage->setApplicableUsers([$uid]);
                    $this->storagesService->addStorage($storage);
                }
            } else {
                // Make home directory if does not exist
                mkdir($home, 0777, true);

                // Home directory (intended) of the user
                $nhome = "$datadir/$uid";

                // Check if correct link or home directory exists
                if (!file_exists($nhome) || is_link($nhome)) {
                    // Unlink if invalid link
                    if (is_link($nhome) && readlink($nhome) != $home) {
                        unlink($nhome);
                    }

                    // Create symlink to directory
                    if (!is_link($nhome) && !symlink($home, $nhome)) {
                        throw new LoginException("Failed to create symlink to home directory");
                    }
                }
            }
        }

        // Update user profile
        if (!$this->config->getSystemValue('oidc_login_proxy_ldap', false)) {
            if ($attr['name'] !== null) {
                $user->setDisplayName($profile[$attr['name']] ?: $profile[$attr['id']]);
            }

            if ($attr['mail'] !== null) {
                $user->setEMailAddress((string)$profile[$attr['mail']]);
            }

            // Set optional params
            if (array_key_exists($attr['quota'], $profile)) {
                $user->setQuota((string) $profile[$attr['quota']]);
            } else {
                if ($defaultQuota = $this->config->getSystemValue('oidc_login_default_quota')) {
                    $user->setQuota((string) $defaultQuota);
                };
            }

            // Groups to add user in
            $groupNames = [];

            // Add administrator group from attribute
            $manageAdmin = array_key_exists('is_admin', $attr) && $attr['is_admin'];
            if ($manageAdmin) {
                $adminAttr = $attr['is_admin'];
                if (array_key_exists($adminAttr, $profile) && $profile[$adminAttr]) {
                    array_push($groupNames, 'admin');
                }
            }

            // Add default group if present
            if ($defaultGroup = $this->config->getSystemValue('oidc_login_default_group')) {
                array_push($groupNames, $defaultGroup);
            }

            // Add user's groups from profile
            $hasProfileGroups = array_key_exists($attr['groups'], $profile);
            if ($hasProfileGroups) {
                // Get group names
                $profileGroups = $profile[$attr['groups']];

                // Explode by space if string
                if (is_string($profileGroups)) {
                    $profileGroups = array_filter(explode(' ', $profileGroups));
                }

                // Make sure group names is an array
                if (!is_array($profileGroups)) {
                    throw new LoginException($attr['groups'] . ' must be an array');
                }

                // Add to all groups
                $groupNames = array_merge($groupNames, $profileGroups);
            }

            // Remove duplicate groups
            $groupNames = array_unique($groupNames);

            // Remove user from groups not present
            $currentUserGroups = $this->groupManager->getUserGroups($user);
            foreach ($currentUserGroups as $currentUserGroup) {
                if (($key = array_search($currentUserGroup->getDisplayName(), $groupNames)) !== false) {
                    // User is already in group - don't process further
                    unset($groupNames[$key]);
                } else {
                    // User is not supposed to be in this group
                    // Remove the user ONLY if we're using profile groups
                    // or the group is the `admin` group and we manage admin role
                    if ($hasProfileGroups || ($manageAdmin && $currentUserGroup->getDisplayName() === 'admin')) {
                        $currentUserGroup->removeUser($user);
                    }
                }
            }

            // Add user to group
            foreach ($groupNames as $group) {
                // Get existing group
                $systemgroup = $this->groupManager->get($group);

                // Create group if does not exist
                if (!$systemgroup && $this->config->getSystemValue('oidc_create_groups', false)) {
                    $systemgroup = $this->groupManager->createGroup($group);
                }

                // Add user to group
                if ($systemgroup) {
                    $systemgroup->addUser($user);
                }
            }
        }
        return array($user, $userPassword);
    }

    private function flatten($array, $prefix = '') {
        $result = array();
        foreach($array as $key => $value) {
            $result[$prefix . $key] = $value;
            if (is_array($value)) {
                $result = $result + $this->flatten($value, $prefix . $key . '_');
            }
            if (is_int($key) && is_string($value)) {
                $result[$prefix . $value] = $value;
            }
        }
        return $result;
    }
}
