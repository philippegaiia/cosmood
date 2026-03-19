<?php

return [

    'label' => 'Producción',

    'confirm_status_change' => 'Confirmar cambio de estado',
    'confirm_saponified_total' => 'Total saponificado diferente del 100%',
    'confirm_multiple' => 'Confirmar cambios',

    'status_transition_body' => 'Va a cambiar de :from a :to.',
    'status_side_effect_ongoing' => 'Esta acción consumirá los lotes asignados para los ingredientes no de embalaje.',
    'status_side_effect_finished' => 'Esta acción finalizará la producción y actualizará los stocks.',
    'status_side_effect_cancelled' => 'Esta acción cancelará la producción y liberará todas las reservas.',

    'saponified_total_mismatch_body' => 'El total saponificado es del :total% en lugar del 100%.',
    'saponified_total_mismatch_hint' => 'Esto puede afectar la coherencia de la receta.',

    'missing_lots_title' => 'Lotes faltantes',
    'missing_lots_body' => 'No se puede finalizar: seleccione un lote para :ingredients.',

    'confirm_continue' => '¿Desea continuar?',

];
