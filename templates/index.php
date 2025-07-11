<div id="advanced-search-app">
    <div class="search-container">
        <h2>Advanced File Search</h2>
        
        <div class="search-form">
            <div class="form-group">
                <label for="filename">Nome do Arquivo:</label>
                <input type="text" id="filename" placeholder="Digite o nome do arquivo...">
            </div>
            
            <div class="form-group">
                <label for="tags">Tags:</label>
                <input type="text" id="tags" placeholder="Digite as tags separadas por vírgula...">
            </div>
            
            <div class="form-group">
                <label>Operador para Tags:</label>
                <input type="radio" id="tag-and" name="tagOperator" value="AND" checked>
                <label for="tag-and">AND (todas as tags)</label>
                
                <input type="radio" id="tag-or" name="tagOperator" value="OR">
                <label for="tag-or">OR (qualquer tag)</label>
            </div>
            
            <button id="search-btn">Buscar</button>
        </div>
        
        <div id="search-results"></div>
    </div>
</div>

<script src="<?php p(\OC::$server->getURLGenerator()->linkTo('advanced-search', 'js/search.js')); ?>"></script>
