import { Application } from 'src/core/shopware';
import CriteriaFactory from 'src/core/factory/criteria.factory';
import StorageBroadcastService from '../storage-broadcaster.service';

class MigrationService {
    constructor(migrationService, migrationDataService, migrationRunService) {
        this._MAX_REQUEST_TIME = 10000; // in ms
        this._DEFAULT_CHUNK_SIZE = 50; // in data sets
        this._CHUNK_INCREMENT = 5; // in data sets
        this.__MIN_INCREMENT = this._CHUNK_INCREMENT;

        this.MIGRATION_STATUS = {
            WAITING: -1,
            FETCH_DATA: 0,
            WRITE_DATA: 1,
            DOWNLOAD_DATA: 2,
            FINISHED: 3
        };

        this._ASSET_ERROR_THRESHOLD = 3;
        this._ASSET_UUID_CHUNK = 100; // Amount of uuids we fetch with one request
        this._ASSET_WORKLOAD_COUNT = 5; // The amount of assets we download per request in parallel
        // The maximum amount of bytes we download per file in one request
        this._ASSET_FILE_CHUNK_BYTE_SIZE = 1000 * 1000 * 8; // 8 MB
        this._CHUNK_SIZE_BYTE_INCREMENT = 250 * 1000; // 250 KB
        this._ASSET_MIN_FILE_CHUNK_BYTE_SIZE = this._CHUNK_SIZE_BYTE_INCREMENT;

        // will be toggled when we receive a response for our 'migrationWanted' request
        this._broadcastResponseFlag = false;

        // handles cross browser tab communication
        this._broadcastService = new StorageBroadcastService(
            this._onBroadcastReceived.bind(this),
            'swag-migration-service'
        );

        this._migrationService = migrationService;
        this._migrationDataService = migrationDataService;
        this._migrationRunService = migrationRunService;
        this._chunkSize = this._DEFAULT_CHUNK_SIZE;

        // state variables
        this._isMigrating = false;
        this._errors = [];
        this._entityGroups = [];
        this._progressSubscriber = null;
        this._statusSubscriber = null;
        this._updateEntityCountSubscriber = null;
        this._runId = '';
        this._profile = null;
        this._status = null;
        this._assetTotalCount = 0;
        this._assetCurrentOffset = 0;
        this._assetUuidPool = [];
        this._assetWorkload = [];
        this._assetProgress = 0;

        this._broadcastService.sendMessage({
            migrationMessage: 'initialized'
        });
    }

    get status() {
        return this._status;
    }

    set status(value) {
        this._status = value;
    }


    get isMigrating() {
        return this._isMigrating;
    }

    get entityGroups() {
        return this._entityGroups;
    }

    get errors() {
        return this._errors;
    }

    subscribeStatus(callback) {
        this._statusSubscriber = callback;
    }

    unsubscribeStatus() {
        this._statusSubscriber = null;
    }

    subscribeProgress(callback) {
        this._progressSubscriber = callback;
    }

    unsubscribeProgress() {
        this._progressSubscriber = null;
    }

    subscribeUpdateEntityCount(callback) {
        this._updateEntityCountSubscriber = callback;
    }

    unsubscribeUpdateEntityCount() {
        this._updateEntityCountSubscriber = null;
    }

    /**
     * @param {String} runId
     * @param {Object} profile
     * @param {Object} entityGroups
     * @param statusCallback
     * @param progressCallback
     * @param updateEntityCountCallback
     * @returns {Promise}
     */
    startMigration(runId, profile, entityGroups, statusCallback, progressCallback, updateEntityCountCallback) {
        return new Promise(async (resolve, reject) => {
            if (this._isMigrating) {
                reject();
                return;
            }

            // Wait for the 'migrationWanted' request and response to allow or deny the migration
            this._isMigrationRunningInOtherTab().then((isRunningInOtherTab) => {
                if (isRunningInOtherTab) {
                    reject();
                    return;
                }

                this._isMigrating = true;
                this._runId = runId;
                this._profile = profile;
                this._entityGroups = entityGroups;
                this._errors = [];
                this.subscribeStatus(statusCallback);
                this.subscribeProgress(progressCallback);
                this.subscribeUpdateEntityCount(updateEntityCountCallback);

                // step 1 - read/fetch
                this._fetchData().then(() => {
                    // step 2 - write data
                    return this._writeData();
                }).then(() => {
                    // step 3 - download data
                    const containsMediaGroup = this._entityGroups.find((group) => {
                        return group.id === 'media' || group.id === 'categories_products';
                    });
                    if (containsMediaGroup !== undefined) {
                        return this._downloadData();
                    }

                    return Promise.resolve();
                }).then(() => {
                    // step 4 - finish -> show results
                    this._migrateFinish();
                    this._isMigrating = false;
                    resolve(this._errors);
                });
            });
        });
    }

    /**
     * Resolves with true if a migration is already running in another tab. otherwise false.
     * It will resolve after 100ms.
     *
     * @returns {Promise}
     * @private
     */
    _isMigrationRunningInOtherTab() {
        return new Promise(async (resolve) => {
            this._broadcastService.sendMessage({
                migrationMessage: 'migrationWanted'
            });

            const oldFlag = this._broadcastResponseFlag;
            setTimeout(() => {
                if (this._broadcastResponseFlag !== oldFlag) {
                    resolve(true);
                    return;
                }

                resolve(false);
            }, 100);
        });
    }

    /**
     * Gets called with data from another browser tab
     *
     * @param data
     * @private
     */
    _onBroadcastReceived(data) {
        // answer incoming migration wanted request based on current migration state.
        if (data.migrationMessage === 'migrationWanted') {
            if (this.isMigrating) {
                this._broadcastService.sendMessage({
                    migrationMessage: 'migrationDenied'
                });
            }
        }

        // allow own migration if no migrationDenied response comes back.
        if (data.migrationMessage === 'migrationDenied') {
            this._broadcastResponseFlag = !this._broadcastResponseFlag;
        }
    }

    _callProgressSubscriber(param) {
        if (this._progressSubscriber !== null) {
            this._progressSubscriber.call(null, param);
        }
    }

    _callStatusSubscriber(param) {
        if (this._statusSubscriber !== null) {
            this._statusSubscriber.call(null, param);
        }
    }

    _callUpdateEntityCountSubscriber(param) {
        if (this._updateEntityCountSubscriber !== null) {
            this._updateEntityCountSubscriber.call(null, param);
        }
    }

    _fetchData() {
        this._resetProgress();
        this._status = this.MIGRATION_STATUS.FETCH_DATA;
        this._callStatusSubscriber({ status: this.status });
        return this._migrateProcess('fetchData');
    }

    _writeData() {
        this._resetProgress();
        this._status = this.MIGRATION_STATUS.WRITE_DATA;
        this._callStatusSubscriber({ status: this.status });

        return this._updateEntityCountForWrite().then(() => {
            return this._migrationRunService.getById(this._runId).then((response) => {
                const totals = response.data.totals;
                const toBeWritten = {};
                this._entityGroups.forEach((entityGroup) => {
                    entityGroup.entities.forEach((entity) => {
                        toBeWritten[entity.entityName] = entity.entityCount;
                    });
                });
                totals.toBeWritten = toBeWritten;

                return this._migrationRunService.updateById(this._runId, { totals: totals }).then(() => {
                    return this._migrateProcess('writeData');
                });
            });
        });
    }

    _downloadData() {
        return this._getAssetTotalCount().then(() => {
            this._resetProgress();
            this._resetAssetProgress();
            this._status = this.MIGRATION_STATUS.DOWNLOAD_DATA;
            this._callStatusSubscriber({ status: this.status });
            return this._downloadProcess();
        });
    }

    _migrateFinish() {
        this._resetProgress();
        this._status = this.MIGRATION_STATUS.FINISHED;
        this._callStatusSubscriber({ status: this.status });
    }

    _resetProgress() {
        this._entityGroups.forEach((group) => {
            group.progress = 0;
        });
    }

    _resetAssetProgress() {
        this._assetCurrentOffset = 0;
        this._assetUuidPool = [];
        this._assetWorkload = [];
        this._assetProgress = 0;
    }

    /**
     * Do all the API requests for all entities with the given methodName
     *
     * @param methodName api endpoint name for example 'fetchData' or 'writeData'
     * @returns {Promise}
     * @private
     */
    async _migrateProcess(methodName) {
        /* eslint-disable no-await-in-loop */
        return new Promise(async (resolve) => {
            for (let i = 0; i < this._entityGroups.length; i += 1) {
                let groupProgress = 0;
                for (let ii = 0; ii < this._entityGroups[i].entities.length; ii += 1) {
                    const entityName = this._entityGroups[i].entities[ii].entityName;
                    const entityCount = this._entityGroups[i].entities[ii].entityCount;
                    await this._migrateEntity(entityName, entityCount, this._entityGroups[i], groupProgress, methodName);
                    groupProgress += entityCount;
                }
            }

            resolve();
        });
        /* eslint-enable no-await-in-loop */
    }

    _updateEntityCountForWrite() {
        return new Promise((resolve) => {
            const countRequests = [];
            this._entityGroups.forEach((entityGroup) => {
                entityGroup.entities.forEach((entity) => {
                    const entityName = entity.entityName;
                    const params = this._getEntityCountParameter(entityName);

                    params.criteria = CriteriaFactory.nested('AND', ...params.criteria);

                    countRequests.push(this._migrationDataService.getList(params));
                });
            });

            Promise.all(countRequests).then((responses) => {
                let i = 0;
                this._entityGroups.forEach((entityGroup) => {
                    let groupsCount = 0;
                    entityGroup.entities.forEach((entity) => {
                        entity.entityCount = parseInt(responses[i].aggregations[entity.entityName].count, 10);
                        groupsCount += entity.entityCount;
                        i += 1;
                    });
                    entityGroup.count = groupsCount;
                });

                this._callUpdateEntityCountSubscriber(this._entityGroups);

                resolve();
            });
        });
    }

    /**
     * @param {String} entityName
     * @returns {Object}
     * @private
     */
    _getEntityCountParameter(entityName) {
        const criteria = [];

        criteria.push(CriteriaFactory.term('runId', this._runId));
        criteria.push(CriteriaFactory.term('entity', entityName));
        criteria.push(CriteriaFactory.not(
            'AND',
            CriteriaFactory.term('converted', null)
        ));

        const count = {};
        count[entityName] = {
            count: { field: 'swag_migration_data.entity' }
        };

        return {
            aggregations: count,
            criteria: criteria,
            limit: 1
        };
    }

    /**
     * Get the count of media objects that are available for the migration.
     *
     * @returns {Promise}
     * @private
     */
    _getAssetTotalCount() {
        return new Promise((resolve) => {
            const params = this._getEntityCountParameter('media');
            params.criteria.push(CriteriaFactory.term('written', true));
            params.criteria = CriteriaFactory.nested('AND', ...params.criteria);
            this._migrationDataService.getList(params).then((res) => {
                this._assetTotalCount = parseInt(res.aggregations.media.count, 10);
                resolve();
            }).catch(() => {
                this._assetTotalCount = 0;
                resolve();
            });
        });
    }

    /**
     * Get a chunk of asset uuids and put it into our pool.
     *
     * @returns {Promise}
     * @private
     */
    _fetchAssetUuidsChunk() {
        return new Promise((resolve) => {
            if (this._assetUuidPool.length >= this._ASSET_WORKLOAD_COUNT) {
                resolve();
                return;
            }

            this._migrationService.fetchAssetUuids({
                profile: this._profile.profile,
                offset: this._assetCurrentOffset,
                limit: this._ASSET_UUID_CHUNK
            }).then((res) => {
                this._assetUuidPool = this._assetUuidPool.concat(res.mediaUuids);
                this._assetCurrentOffset += this._ASSET_UUID_CHUNK;
                resolve();
            });
        });
    }

    /**
     * Download all media files to filesystem
     *
     * @returns {Promise}
     * @private
     */
    async _downloadProcess() {
        /* eslint-disable no-await-in-loop */
        return new Promise(async (resolve) => {
            await this._fetchAssetUuidsChunk();

            // make workload
            this._makeWorkload(this._ASSET_WORKLOAD_COUNT);

            while (this._assetProgress < this._assetTotalCount) {
                // send workload to api
                let newWorkload;
                const beforeRequestTime = new Date();

                await this._downloadAssets().then((w) => {
                    newWorkload = w;
                });

                const afterRequestTime = new Date();
                // process response and update local workload
                this._updateWorkload(newWorkload, afterRequestTime - beforeRequestTime);

                await this._fetchAssetUuidsChunk();

                if (this._assetUuidPool.length === 0 && newWorkload.length === 0) {
                    break;
                }
            }

            resolve();
        });
        /* eslint-enable no-await-in-loop */
    }

    /**
     * Push asset uuids from the pool into the current workload
     *
     * @param assetCount the amount of uuids to add
     * @private
     */
    _makeWorkload(assetCount) {
        const uuids = this._assetUuidPool.splice(0, assetCount);
        uuids.forEach((uuid) => {
            this._assetWorkload.push({
                uuid,
                currentOffset: 0,
                state: 'inProgress'
            });
        });
    }

    /**
     * Analyse the given workload and update our own workload.
     * Remove finished assets from our workload and add new ones.
     * Remove failed assets (errorCount >= this._ASSET_ERROR_THRESHOLD) and add errors for them.
     * Make sure we have the asset amount in our workload that we specified (this._ASSET_WORKLOAD_COUNT).
     *
     * @param newWorkload
     * @param requestTime
     * @private
     */
    _updateWorkload(newWorkload, requestTime) {
        const finishedAssets = newWorkload.filter((asset) => asset.state === 'finished');
        let assetsRemovedCount = finishedAssets.length;
        this._assetWorkload = newWorkload.filter((asset) => asset.state === 'inProgress');

        // check for errorCount
        this._assetWorkload = this._assetWorkload.filter((asset) => {
            if (!asset.errorCount || (asset.errorCount && asset.errorCount <= this._ASSET_ERROR_THRESHOLD)) {
                return true;
            }

            assetsRemovedCount += 1;
            this._addError({
                code: '0',
                status: '444',
                title: this.applicationRoot.$i18n.tc('swag-migration.index.error.canNotDownloadAsset.title'),
                detail: this.applicationRoot.$i18n.tc('swag-migration.index.error.canNotDownloadAsset.detail'),
                information: this.applicationRoot.$i18n.t(
                    'swag-migration.index.error.canNotDownloadAsset.information',
                    { path: asset.additionalData.uri }
                ),
                path: asset.additionalData.uri,
                trace: []
            });

            return false;
        });

        // Get the assets that have utilized the full amount of fileByteChunkSize
        const assetsWithoutAnyErrors = this._assetWorkload.filter((asset) => !asset.errorCount);
        if (assetsWithoutAnyErrors.length !== 0) {
            this._handleAssetFileChunkByteSize(requestTime);
        }

        this._assetProgress += assetsRemovedCount;
        // call event subscriber
        this._callProgressSubscriber({
            entityName: 'media',
            entityGroupProgressValue: this._assetProgress,
            entityCount: this._assetTotalCount
        });

        this._makeWorkload(assetsRemovedCount);
    }

    /**
     * Send the asset download request with our workload and fileChunkByteSize.
     *
     * @returns {Promise}
     * @private
     */
    _downloadAssets() {
        return new Promise((resolve) => {
            this._migrationService.downloadAssets({
                workload: this._assetWorkload,
                fileChunkByteSize: this._ASSET_FILE_CHUNK_BYTE_SIZE
            }).then((res) => {
                resolve(res.workload);
            }).catch(() => {
                resolve(this._assetWorkload);
            });
        });
    }

    /**
     * Do all the API requests for one entity in chunks
     *
     * @param entityName
     * @param entityCount
     * @param group
     * @param groupProgress
     * @param methodName
     * @returns {Promise<void>}
     * @private
     */
    async _migrateEntity(entityName, entityCount, group, groupProgress, methodName) {
        let currentOffset = 0;
        /* eslint-disable no-await-in-loop */
        while (currentOffset < entityCount) {
            const oldChunkSize = this._chunkSize;
            await this._migrateEntityRequest(entityName, group.targetId, group.target, methodName, currentOffset);
            let newOffset = currentOffset + oldChunkSize;
            if (newOffset > entityCount) {
                newOffset = entityCount;
            }

            // update own state of progress
            group.progress = groupProgress + newOffset;

            // call event subscriber
            this._callProgressSubscriber({
                entityName,
                entityGroupProgressValue: groupProgress + newOffset,
                entityCount: group.count
            });

            currentOffset += oldChunkSize;
        }
        /* eslint-enable no-await-in-loop */

        this._chunkSize = this._DEFAULT_CHUNK_SIZE;
    }

    /**
     * Do a single API request for the given entity with given offset.
     *
     * @param entityName
     * @param targetId
     * @param target
     * @param methodName
     * @param offset
     * @returns {Promise}
     * @private
     */
    _migrateEntityRequest(entityName, targetId, target, methodName, offset) {
        return new Promise((resolve) => {
            const params = {
                runUuid: this._runId,
                profile: this._profile.profile,
                gateway: this._profile.gateway,
                credentialFields: this._profile.credentialFields,
                entity: entityName,
                offset: offset,
                limit: this._chunkSize
            };

            if (target === 'catalog') {
                params.catalogId = targetId;
            } else {
                params.salesChannelId = targetId;
            }

            const beforeRequestTime = new Date();
            this._migrationService[methodName](params).then((response) => {
                if (!response) {
                    this._addError({
                        code: '0',
                        detail: this.applicationRoot.$i18n.tc('swag-migration.index.error.canNotConnectToServer.detail'),
                        status: '444',
                        title: this.applicationRoot.$i18n.tc('swag-migration.index.error.canNotConnectToServer.title'),
                        information: this.applicationRoot.$i18n.tc(
                            'swag-migration.index.error.canNotConnectToServer.information'
                        ),
                        trace: []
                    });
                    resolve();
                    return;
                }

                const afterRequestTime = new Date();
                this._handleChunkSize(afterRequestTime.getTime() - beforeRequestTime.getTime());
                resolve();
            }).catch((response) => {
                if (!response || !response.response) {
                    this._addError({
                        code: '0',
                        detail: this.applicationRoot.$i18n.tc('swag-migration.index.error.canNotConnectToServer.detail'),
                        status: '444',
                        title: this.applicationRoot.$i18n.tc('swag-migration.index.error.canNotConnectToServer.title'),
                        information: this.applicationRoot.$i18n.tc(
                            'swag-migration.index.error.canNotConnectToServer.information'
                        ),
                        trace: []
                    });
                    resolve();
                    return;
                }

                if (response.response.data && response.response.data.errors) {
                    response.response.data.errors.forEach((error) => {
                        this._addError(error);
                    });
                }

                const afterRequestTime = new Date();
                this._handleChunkSize(afterRequestTime.getTime() - beforeRequestTime.getTime());
                resolve();
            });
        });
    }

    /**
     * Update the chunkSize depending on the requestTime
     *
     * @param {int} requestTime Request time in milliseconds
     * @private
     */
    _handleChunkSize(requestTime) {
        if (requestTime < this._MAX_REQUEST_TIME) {
            this._chunkSize += this._CHUNK_INCREMENT;
        }

        if (
            requestTime > this._MAX_REQUEST_TIME &&
            (this._chunkSize - this._CHUNK_INCREMENT) >= this.__MIN_INCREMENT
        ) {
            this._chunkSize -= this._CHUNK_INCREMENT;
        }
    }

    /**
     * Update the ASSET_FILE_CHUNK_BYTE_SIZE depending on the requestTime
     *
     * @param {int} requestTime Request time in milliseconds
     * @private
     */
    _handleAssetFileChunkByteSize(requestTime) {
        if (requestTime < this._MAX_REQUEST_TIME) {
            this._ASSET_FILE_CHUNK_BYTE_SIZE += this._CHUNK_SIZE_BYTE_INCREMENT;
        }

        if (
            requestTime > this._MAX_REQUEST_TIME &&
            (this._ASSET_FILE_CHUNK_BYTE_SIZE - this._CHUNK_SIZE_BYTE_INCREMENT) >= this._ASSET_MIN_FILE_CHUNK_BYTE_SIZE
        ) {
            this._ASSET_FILE_CHUNK_BYTE_SIZE -= this._CHUNK_SIZE_BYTE_INCREMENT;
        }
    }

    _addError(error) {
        this._errors.push(error);
    }

    get applicationRoot() {
        if (this._applicationRoot) {
            return this._applicationRoot;
        }
        this._applicationRoot = Application.getApplicationRoot();
        return this._applicationRoot;
    }
}

export default MigrationService;
