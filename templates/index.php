<?php
// No início do arquivo, adicionar:
\OC::$server->getL10NFactory()->get('advancedsearch')->getLanguageCode();
script('advancedsearch', 'l10n/' . \OC::$server->getL10NFactory()->findLanguage('advancedsearch'));
script('viewer', 'viewer-init');    // Inicializa o viewer
style('viewer', 'style');  
script('advancedsearch', 'search');
style('advancedsearch', 'style');
?>

<div id="app">
    <div id="app-navigation">
        <div class="app-navigation-new">
            <h2><?php p($l->t('Advanced Search')); ?></h2>
        </div>
        
        <div class="search-sidebar">
            <div class="search-section">
                <h3><?php p($l->t('Search Criteria')); ?></h3>
                
                <div class="search-form">
                    <div class="form-group">
                        <label for="filename"><?php p($l->t('File Name')); ?>:</label>
                        <input type="text" id="filename" class="input-field" placeholder="<?php p($l->t('Enter file name...')); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="tags"><?php p($l->t('Tags')); ?>:</label>
                        <input type="text" id="tags" class="input-field" placeholder="<?php p($l->t('Enter tags separated by comma...')); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label><?php p($l->t('Tag Operator')); ?>:</label>
                        <div class="radio-group">
                            <label>
                                <input type="radio" id="tag-and" name="tagOperator" value="AND" checked>
                                <?php p($l->t('AND (all tags)')); ?>
                            </label>
                            
                            <label>
                                <input type="radio" id="tag-or" name="tagOperator" value="OR">
                                <?php p($l->t('OR (any tag)')); ?>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="file-type"><?php p($l->t('File Type')); ?>:</label>
                        <select id="file-type" class="input-field">
                            <option value=""><?php p($l->t('All types')); ?></option>
                            <option value="image"><?php p($l->t('Images')); ?></option>
                            <option value="document"><?php p($l->t('Documents')); ?></option>
                            <option value="video"><?php p($l->t('Videos')); ?></option>
                            <option value="audio"><?php p($l->t('Audio')); ?></option>
                            <option value="pdf"><?php p($l->t('PDF')); ?></option>
                        </select>
                    </div>
                    
                    <button id="search-btn" class="primary button"><?php p($l->t('Search')); ?></button>
                    <button id="clear-btn" class="button"><?php p($l->t('Clear')); ?></button>
                </div>
            </div>
            
            <div class="search-stats">
                <div id="search-info">
                    <span id="result-count"></span>
                </div>
            </div>
        </div>
    </div>

    <div id="app-content">
        <div id="app-content-wrapper">
            <div class="files-controls">
                <div class="files-controls-inner">
                    <div class="breadcrumb">
                        <div class="crumb svg canDrop">
                            <a href="#" class="icon-home"></a>
                            <span><?php p($l->t('Search Results')); ?></span>
                        </div>
                    </div>
                    
                    <!-- Paginação adicionada aqui -->
                    <div class="pagination-wrapper hidden" id="pagination">
                        <div class="pagination-info">
                            <span id="pagination-info"><?php p($l->t('0-0 of 0 results')); ?></span>
                        </div>
                        
                        <div class="pagination-controls">
                            <div class="pagination-buttons">
                                <button class="pagination-button" id="first-page" title="<?php p($l->t('First page')); ?>">«</button>
                                <button class="pagination-button" id="prev-page" title="<?php p($l->t('Previous page')); ?>">‹</button>
                                
                                <div id="page-numbers" style="display: inline-flex;">
                                    <!-- Números das páginas serão inseridos aqui -->
                                </div>
                                
                                <button class="pagination-button" id="next-page" title="<?php p($l->t('Next page')); ?>">›</button>
                                <button class="pagination-button" id="last-page" title="<?php p($l->t('Last page')); ?>">»</button>
                            </div>
                            
                            <div class="page-size-selector">
                                <label for="page-size"><?php p($l->t('Itens')); ?>:</label>
                                <select id="page-size">
                                    <option value="10">10</option>
                                    <option value="25" selected>25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Botões de alternância de visualização -->
                    <!-- <div class="view-controls">
                        <div class="view-buttons">
                            <button id="view-list" class="view-button" title="<?php p($l->t('List view')); ?>">
                                <span class="icon-list"></span>
                                <span class="button-text"><?php p($l->t('List')); ?></span>
                            </button>
                            <button id="view-grid" class="view-button active" title="<?php p($l->t('Grid view')); ?>">
                                <span class="icon-toggle-pictures"></span>
                                <span class="button-text"><?php p($l->t('Grid')); ?></span>
                            </button>
                        </div>
                    </div> -->
                </div>
            </div>
            
            <div id="emptycontent">
                <div class="icon-search"></div>
                <h2><?php p($l->t('No results found')); ?></h2>
                <p><?php p($l->t('Try adjusting your search criteria')); ?></p>
            </div>
            
            <div id="loading" class="hidden">
                <div class="icon-loading"></div>
                <h2><?php p($l->t('Searching...')); ?></h2>
            </div>
            
            <table id="filestable" class="list-container hidden">
                <thead>
                    <tr>
                        <th id="headerName" class="column-name">
                            <div id="headerName-content">
                                <span class="sort-indicator icon-triangle-s"></span>
                                <span><?php p($l->t('Name')); ?></span>
                            </div>
                        </th>
                        <th id="headerSize" class="column-size">
                            <span><?php p($l->t('Size')); ?></span>
                        </th>
                        <th id="headerDate" class="column-mtime">
                            <span><?php p($l->t('Modified')); ?></span>
                        </th>
                        <th id="headerTags" class="column-tags">
                            <span><?php p($l->t('Tags')); ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody id="fileList">
                    <!-- Resultados serão inseridos aqui -->
                </tbody>
            </table>
            
            
        </div>
    </div>
</div>