<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      http://syspass.org
 * @copyright 2012-2018, Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 *  along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace SP\Modules\Web\Controllers\Helpers\Account;

use SP\Account\AccountAcl;
use SP\Core\Acl\Acl;
use SP\Core\Acl\ActionsInterface;
use SP\Core\Acl\UnauthorizedPageException;
use SP\Core\Exceptions\SPException;
use SP\DataModel\Dto\AccountAclDto;
use SP\DataModel\Dto\AccountDetailsResponse;
use SP\Mgmt\Users\UserPass;
use SP\Modules\Web\Controllers\Helpers\HelperBase;
use SP\Modules\Web\Controllers\Traits\ItemTrait;
use SP\Mvc\View\Components\SelectItemAdapter;
use SP\Services\Account\AccountHistoryService;
use SP\Services\Account\AccountService;
use SP\Services\Category\CategoryService;
use SP\Services\Client\ClientService;
use SP\Services\PublicLink\PublicLinkService;
use SP\Services\Tag\TagService;
use SP\Services\User\UpdatedMasterPassException;
use SP\Services\User\UserService;
use SP\Services\UserGroup\UserGroupService;
use SP\Util\ErrorUtil;

/**
 * Class AccountHelper
 *
 * @package SP\Modules\Web\Controllers\Helpers
 */
class AccountHelper extends HelperBase
{
    use ItemTrait;

    /**
     * @var  Acl
     */
    protected $acl;
    /**
     * @var AccountService
     */
    protected $accountService;
    /**
     * @var AccountHistoryService
     */
    protected $accountHistoryService;
    /**
     * @var PublicLinkService
     */
    protected $publicLinkService;
    /**
     * @var string
     */
    private $actionId;
    /**
     * @var AccountAcl
     */
    private $accountAcl;
    /**
     * @var int con el Id de la cuenta
     */
    private $accountId;
    /**
     * @var bool
     */
    private $isView = false;

    /**
     * @param Acl                   $acl
     * @param AccountService        $accountService
     * @param AccountHistoryService $accountHistoryService
     * @param PublicLinkService     $publicLinkService
     */
    public function inject(Acl $acl,
                           AccountService $accountService,
                           AccountHistoryService $accountHistoryService,
                           PublicLinkService $publicLinkService
    )
    {
        $this->acl = $acl;
        $this->accountService = $accountService;
        $this->accountHistoryService = $accountHistoryService;
        $this->publicLinkService = $publicLinkService;
    }

    /**
     * Sets account's view variables
     *
     * @param AccountDetailsResponse $accountDetailsResponse
     * @param int                    $actionId
     * @throws SPException
     * @throws UnauthorizedPageException
     * @throws UpdatedMasterPassException
     * @throws \SP\Core\Dic\ContainerException
     */
    public function setViewForAccount(AccountDetailsResponse $accountDetailsResponse, $actionId)
    {
        $this->accountId = $accountDetailsResponse->getAccountVData()->getId();
        $this->actionId = $actionId;
        $this->accountAcl = new AccountAcl($actionId);

        $this->checkActionAccess();
        $this->checkAccess($accountDetailsResponse);

        $accountData = $accountDetailsResponse->getAccountVData();
        $selectUsers = SelectItemAdapter::factory(UserService::getItemsBasic());
        $selectUserGroups = SelectItemAdapter::factory(UserGroupService::getItemsBasic());
        $selectTags = SelectItemAdapter::factory(TagService::getItemsBasic());

        $this->view->assign('otherUsers', $selectUsers->getItemsFromModelSelected(SelectItemAdapter::getIdFromArrayOfObjects($accountDetailsResponse->getUsers()), $accountData->getUserId()));
        $this->view->assign('otherUserGroups', $selectUserGroups->getItemsFromModelSelected(SelectItemAdapter::getIdFromArrayOfObjects($accountDetailsResponse->getUserGroups()), $accountData->getUserGroupId()));
        $this->view->assign('userGroups', $selectUserGroups->getItemsFromModelSelected([$accountData->getUserGroupId()]));
        $this->view->assign('tags', $selectTags->getItemsFromModelSelected(SelectItemAdapter::getIdFromArrayOfObjects($accountDetailsResponse->getTags())));

        $this->view->assign('historyData', $this->accountHistoryService->getHistoryForAccount($this->accountId));

        $this->view->assign('isModified', strtotime($accountData->getDateEdit()) !== false);
        $this->view->assign('maxFileSize', round($this->configData->getFilesAllowedSize() / 1024, 1));
        $this->view->assign('filesAllowedExts', implode(',', $this->configData->getFilesAllowedExts()));

        if ($this->configData->isPublinksEnabled() && $this->accountAcl->isShowLink()) {
            $publicLinkData = $this->publicLinkService->getHashForItem($this->accountId);

            $publicLinkUrl = $publicLinkData ? PublicLinkService::getLinkForHash($publicLinkData->getHash()) : null;
            $this->view->assign('publicLinkUrl', $publicLinkUrl);
            $this->view->assign('publicLinkId', $publicLinkData ? $publicLinkData->getId() : 0);
            $this->view->assign('publicLinkShow', true);
        } else {
            $this->view->assign('publicLinkShow', false);
        }

        $this->view->assign('accountPassDate', date('Y-m-d H:i:s', $accountData->getPassDate()));
        $this->view->assign('accountPassDateChange', date('Y-m-d', $accountData->getPassDateChange() ?: 0));
        $this->view->assign('linkedAccounts', $this->accountService->getLinked($this->accountId));

        $this->view->assign('accountId', $accountData->getId());
        $this->view->assign('accountData', $accountData);
        $this->view->assign('gotData', true);

        $this->view->assign('actions', $this->getActionsHelper()->getActionsForAccount($this->accountAcl->getStoredAcl(), new AccountActionsDto($this->accountId, null, $accountData->getParentId())));

        $this->setViewCommon();
    }

    /**
     * @throws UnauthorizedPageException
     * @throws UpdatedMasterPassException
     */
    public function checkActionAccess()
    {
        if (!$this->acl->checkUserAccess($this->actionId)) {
            throw new UnauthorizedPageException(SPException::SP_INFO);
        }

        if (!UserPass::checkUserUpdateMPass($this->session->getUserData()->getId())) {
            throw new UpdatedMasterPassException(SPException::SP_INFO);
        }
    }

    /**
     * Comprobar si el usuario dispone de acceso al módulo
     *
     * @param AccountDetailsResponse $accountDetailsResponse
     * @return bool
     */
    protected function checkAccess(AccountDetailsResponse $accountDetailsResponse)
    {
        $accountData = $accountDetailsResponse->getAccountVData();

        $acccountAclDto = new AccountAclDto();
        $acccountAclDto->setAccountId($accountData->getId());
        $acccountAclDto->setDateEdit(strtotime($accountData->getDateEdit()));
        $acccountAclDto->setUserId($accountData->getUserId());
        $acccountAclDto->setUserGroupId($accountData->getUserGroupId());
        $acccountAclDto->setUsersId($accountDetailsResponse->getUsers());
        $acccountAclDto->setUserGroupsId($accountDetailsResponse->getUserGroups());

        if (!$this->accountAcl->getAcl($acccountAclDto)->checkAccountAccess()) {
            ErrorUtil::showErrorInView($this->view, ErrorUtil::ERR_ACCOUNT_NO_PERMISSION);

            return false;
        }

        return true;
    }

    /**
     * @return AccountActionsHelper
     * @throws \SP\Core\Dic\ContainerException
     */
    protected function getActionsHelper()
    {
        return new AccountActionsHelper($this->view, $this->config, $this->session, $this->eventDispatcher);
    }

    /**
     * Sets account's view common data
     */
    protected function setViewCommon()
    {
        $userProfileData = $this->session->getUserProfile();

        $this->view->assign('actionId', $this->actionId);
        $this->view->assign('isView', $this->isView);

        $this->view->assign('accountIsHistory', false);

        $this->view->assign('customFields', $this->getCustomFieldsForItem(ActionsInterface::ACCOUNT, $this->accountId));
        $this->view->assign('categories', SelectItemAdapter::factory(CategoryService::getItemsBasic())->getItemsFromModel());
        $this->view->assign('clients', SelectItemAdapter::factory(ClientService::getItemsBasic())->getItemsFromModel());

        $this->view->assign('allowPrivate', $userProfileData->isAccPrivate());
        $this->view->assign('allowPrivateGroup', $userProfileData->isAccPrivateGroup());
        $this->view->assign('mailRequestEnabled', $this->configData->isMailRequestsEnabled());
        $this->view->assign('passToImageEnabled', $this->configData->isAccountPassToImage());

        $this->view->assign('otherAccounts', $this->accountService->getForUser($this->accountId));

        $this->view->assign('addClientEnabled', !$this->isView && $this->acl->checkUserAccess(ActionsInterface::CLIENT));
        $this->view->assign('addClientRoute', Acl::getActionRoute(ActionsInterface::CLIENT_CREATE));

        $this->view->assign('addCategoryEnabled', !$this->isView && $this->acl->checkUserAccess(ActionsInterface::CATEGORY));
        $this->view->assign('addCategoryRoute', Acl::getActionRoute(ActionsInterface::CATEGORY_CREATE));

        $this->view->assign('fileListRoute', Acl::getActionRoute(ActionsInterface::ACCOUNT_FILE_LIST));
        $this->view->assign('fileUploadRoute', Acl::getActionRoute(ActionsInterface::ACCOUNT_FILE_UPLOAD));

        $this->view->assign('disabled', $this->isView ? 'disabled' : '');
        $this->view->assign('readonly', $this->isView ? 'readonly' : '');

        $this->view->assign('showViewCustomPass', $this->accountAcl->isShowViewPass());
        $this->view->assign('accountAcl', $this->accountAcl->getStoredAcl() ?: $this->accountAcl);
    }

    /**
     * Sets account's view for a blank form
     *
     * @param $actionId
     * @return void
     * @throws UnauthorizedPageException
     * @throws UpdatedMasterPassException
     * @throws \SP\Core\Dic\ContainerException
     */
    public function setViewForBlank($actionId)
    {
        $this->actionId = $actionId;
        $this->accountAcl = new AccountAcl($actionId);

        $this->checkActionAccess();

        $selectUsers = SelectItemAdapter::factory(UserService::getItemsBasic());
        $selectUserGroups = SelectItemAdapter::factory(UserGroupService::getItemsBasic());
        $selectTags = SelectItemAdapter::factory(TagService::getItemsBasic());

        $this->view->assign('accountPassDateChange', date('Y-m-d', time() + 7776000));
        $this->view->assign('otherUsers', $selectUsers->getItemsFromModel());
        $this->view->assign('otherUserGroups', $selectUserGroups->getItemsFromModel());
        $this->view->assign('userGroups', $selectUserGroups->getItemsFromModel());
        $this->view->assign('tags', $selectTags->getItemsFromModel());

        $this->view->assign('accountId', 0);
        $this->view->assign('gotData', false);

        $this->view->assign('actions', $this->getActionsHelper()->getActionsForAccount($this->accountAcl, new AccountActionsDto($this->accountId)));

        $this->setViewCommon();
    }

    /**
     * Sets account's view variables
     *
     * @param AccountDetailsResponse $accountDetailsResponse
     * @param int                    $actionId
     * @return bool
     * @throws UnauthorizedPageException
     * @throws UpdatedMasterPassException
     * @throws \SP\Core\Dic\ContainerException
     */
    public function setViewForRequest(AccountDetailsResponse $accountDetailsResponse, $actionId)
    {
        $this->accountId = $accountDetailsResponse->getAccountVData()->getId();
        $this->actionId = $actionId;
        $this->accountAcl = new AccountAcl($actionId);

        $this->checkActionAccess();

        $accountData = $accountDetailsResponse->getAccountVData();

        $this->view->assign('accountId', $accountData->getId());
        $this->view->assign('accountData', $accountDetailsResponse->getAccountVData());

        $this->view->assign('actions', $this->getActionsHelper()->getActionsForAccount($this->accountAcl->getStoredAcl(), new AccountActionsDto($this->accountId, null, $accountData->getParentId())));

        return true;
    }

    /**
     * @param bool $isView
     */
    public function setIsView($isView)
    {
        $this->isView = (bool)$isView;
    }

    /**
     * Initialize class
     */
    protected function initialize()
    {
        $this->view->assign('changesHash');
        $this->view->assign('chkUserEdit');
        $this->view->assign('chkGroupEdit');
        $this->view->assign('sk', $this->session->generateSecurityKey());
    }
}