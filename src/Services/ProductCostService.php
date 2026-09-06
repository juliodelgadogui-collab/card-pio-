<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class ProductCostService
{
    public function economics(int $productId, ?int $tenantId = null): array
    {
        $tenantId ??= Auth::tenantId();
        if (!$tenantId || $productId < 1) throw new RuntimeException('Produto inválido.');
        $pdo = Database::connection();
        $p = $pdo->prepare('SELECT id,name,price_cents,average_cost_cents FROM products WHERE id=? AND tenant_id=? LIMIT 1');
        $p->execute([$productId,$tenantId]);
        $product = $p->fetch();
        if (!$product) throw new RuntimeException('Produto não encontrado.');

        $recipe = $this->recipe($pdo,$tenantId,$productId);
        $recipeCost = 0;
        foreach ($recipe as &$row) {
            $qty = (float)$row['quantity'] * (1 + max(0.0,(float)$row['waste_percent']) / 100);
            $row['effective_quantity'] = round($qty,6);
            $row['line_cost_cents'] = (int)round($qty * (int)$row['average_cost_cents']);
            $recipeCost += $row['line_cost_cents'];
        }
        unset($row);

        $baseCost = $recipe ? $recipeCost : (int)$product['average_cost_cents'];
        $price = (int)$product['price_cents'];
        $profit = $price - $baseCost;
        $margin = $price > 0 ? ($profit / $price) * 100 : 0.0;
        $markup = $baseCost > 0 ? ($price / $baseCost) : null;

        return [
            'product'=>$product,
            'recipe'=>$recipe,
            'cost_cents'=>$baseCost,
            'price_cents'=>$price,
            'profit_cents'=>$profit,
            'margin_percent'=>round($margin,2),
            'markup'=> $markup !== null ? round($markup,3) : null,
        ];
    }

    public function saveRecipeItem(int $productId,int $ingredientId,float $quantity,float $wastePercent=0):void
    {
        Auth::requirePermission('catalog.manage');
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        if($productId<1||$ingredientId<1||$productId===$ingredientId)throw new RuntimeException('Ingrediente inválido.');
        if(!is_finite($quantity)||$quantity<=0)throw new RuntimeException('Quantidade da ficha técnica inválida.');
        if(!is_finite($wastePercent)||$wastePercent<0||$wastePercent>500)throw new RuntimeException('Percentual de perda inválido.');
        Database::transaction(function(PDO $pdo)use($tenantId,$productId,$ingredientId,$quantity,$wastePercent):void{
            foreach([$productId,$ingredientId] as $id){$s=$pdo->prepare('SELECT id FROM products WHERE id=? AND tenant_id=?');$s->execute([$id,$tenantId]);if(!$s->fetchColumn())throw new RuntimeException('Produto/ingrediente não pertence à empresa.');}
            $check=$pdo->prepare('SELECT id FROM product_recipes WHERE tenant_id=? AND product_id=? AND ingredient_product_id=?');$check->execute([$tenantId,$productId,$ingredientId]);$id=$check->fetchColumn();
            if($id)$pdo->prepare('UPDATE product_recipes SET quantity=?,waste_percent=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$quantity,$wastePercent,$id]);
            else $pdo->prepare('INSERT INTO product_recipes (tenant_id,product_id,ingredient_product_id,quantity,waste_percent) VALUES (?,?,?,?,?)')->execute([$tenantId,$productId,$ingredientId,$quantity,$wastePercent]);
            Auth::audit('product.recipe_saved','product',(string)$productId,['ingredient_product_id'=>$ingredientId,'quantity'=>$quantity,'waste_percent'=>$wastePercent]);
        });
    }

    public function removeRecipeItem(int $productId,int $recipeId):void
    {
        Auth::requirePermission('catalog.manage');$tenantId=Auth::tenantId();if(!$tenantId)return;
        $s=Database::connection()->prepare('DELETE FROM product_recipes WHERE id=? AND tenant_id=? AND product_id=?');$s->execute([$recipeId,$tenantId,$productId]);
        Auth::audit('product.recipe_removed','product',(string)$productId,['recipe_id'=>$recipeId]);
    }

    private function recipe(PDO $pdo,int $tenantId,int $productId):array
    {
        $s=$pdo->prepare('SELECT r.*,p.name ingredient_name,p.sku ingredient_sku,p.average_cost_cents,p.track_stock,p.stock_qty FROM product_recipes r JOIN products p ON p.id=r.ingredient_product_id WHERE r.tenant_id=? AND r.product_id=? ORDER BY p.name');
        $s->execute([$tenantId,$productId]);return $s->fetchAll();
    }
}
