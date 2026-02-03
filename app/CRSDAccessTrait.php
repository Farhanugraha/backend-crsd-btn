<?php
// app/Traits/CRSDAccessTrait.php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait CRSDAccessTrait
{
    /**
     * Get user's data access
     */
    protected function getUserDataAccess()
    {
        $user = auth()->guard('api')->user();
        if (!$user) {
            return [];
        }
        
        $dataAccess = json_decode($user->data_access ?? '[]', true);
        return is_array($dataAccess) ? $dataAccess : [];
    }
    
    /**
     * Check if user has access to specific CRSD
     */
    protected function hasCRSDAccess($crsdType)
    {
        $user = auth()->guard('api')->user();
        
        if (!$user) {
            return false;
        }
        
        if ($user->role === 'superadmin') {
            return true;
        }
        
        $dataAccess = $this->getUserDataAccess();
        
        if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
            return true;
        }
        
        return in_array($crsdType, $dataAccess);
    }
    
    /**
     * Check if user has multiple CRSD access
     */
    protected function hasMultipleCRSDAccess()
    {
        $dataAccess = $this->getUserDataAccess();
        $crsdTypes = array_filter($dataAccess, function($item) {
            return in_array($item, ['crsd1', 'crsd2']);
        });
        return count($crsdTypes) > 1;
    }
    
    /**
     * Get CRSD filter for query
     */
    protected function getCRSDFilter($relation = 'user')
    {
        $user = auth()->guard('api')->user();
        
        if (!$user) {
            return null;
        }
        
        if ($user->role === 'superadmin') {
            return null;
        }
        
        $dataAccess = $this->getUserDataAccess();
        
        if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
            return null;
        }
        
        if (in_array('crsd1', $dataAccess)) {
            return function($query) use ($relation) {
                $query->whereHas($relation, function($q) {
                    $q->where('divisi', 'CRSD 1');
                });
            };
        }
        
        if (in_array('crsd2', $dataAccess)) {
            return function($query) use ($relation) {
                $query->whereHas($relation, function($q) {
                    $q->where('divisi', 'CRSD 2');
                });
            };
        }
        
        return function($query) {
            $query->whereRaw('1 = 0');
        };
    }
    
    /**
     * Apply CRSD filter to query
     */
    protected function applyCRSDFilter($query, $relation = 'user')
    {
        $filter = $this->getCRSDFilter($relation);
        
        if ($filter) {
            $filter($query);
        }
        
        return $query;
    }
    
    /**
     * Get CRSD type from user access
     */
    protected function getUserCRSDType()
    {
        $dataAccess = $this->getUserDataAccess();
        $crsdTypes = array_filter($dataAccess, function($item) {
            return in_array($item, ['crsd1', 'crsd2']);
        });
        
        if (count($crsdTypes) === 1) {
            return reset($crsdTypes);
        }
        
        return null;
    }
}