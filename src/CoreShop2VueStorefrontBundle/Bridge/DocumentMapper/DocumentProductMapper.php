<?php

namespace CoreShop2VueStorefrontBundle\Bridge\DocumentMapper;

use Cocur\Slugify\SlugifyInterface;
use CoreShop\Component\Core\Model\ProductInterface;
use CoreShop\Component\Core\Model\StoreInterface;
use CoreShop\Component\Core\Repository\ProductRepositoryInterface;
use CoreShop2VueStorefrontBundle\Bridge\DocumentMapperInterface;
use CoreShop2VueStorefrontBundle\Bridge\Helper\DocumentHelper;
use CoreShop2VueStorefrontBundle\Bridge\Helper\PriceHelper;
use CoreShop2VueStorefrontBundle\Bridge\StoreAwareDocumentMapperInterface;
use CoreShop2VueStorefrontBundle\Document\MediaGallery;
use CoreShop2VueStorefrontBundle\Document\Product;
use CoreShop2VueStorefrontBundle\Document\ProductCategory;
use CoreShop2VueStorefrontBundle\Document\Stock;
use ONGR\ElasticsearchBundle\Service\IndexService;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\DataObject\AbstractObject;

class DocumentProductMapper extends AbstractMapper implements StoreAwareDocumentMapperInterface
{
    /** @var SlugifyInterface */
    protected $slugify;
    /** @var ProductRepositoryInterface */
    protected $productRepository;
    /** @var PriceHelper */
    private $priceHelper;
    /** @var DocumentHelper */
    private $documentHelper;

    /**
     * @param SlugifyInterface           $slugify
     * @param ProductRepositoryInterface $productRepository
     * @param PriceHelper                $priceHelper
     */
    public function __construct(
        SlugifyInterface $slugify,
        ProductRepositoryInterface $productRepository,
        PriceHelper $priceHelper,
        DocumentHelper $documentHelper
    ) {
        $this->slugify = $slugify;
        $this->productRepository = $productRepository;
        $this->priceHelper = $priceHelper;
        $this->documentHelper = $documentHelper;
    }

    public function supports($objectOrClass): bool
    {
        if (is_string($objectOrClass)) {
            return is_a($objectOrClass, ProductInterface::class, true);
        }

        return $objectOrClass instanceof ProductInterface && [] === $objectOrClass->getChildren([AbstractObject::OBJECT_TYPE_VARIANT], true);
    }

    /**
     * @param ProductInterface $product
     *
     * @return Product
     */
    public function mapToDocument(IndexService $service, object $product, ?string $language = null, ?StoreInterface $store = null): Product
    {
        $esProduct = $this->find($service, $product);

        $productName = $product->getName($language) ?: $product->getKey();

        $esProduct->setId($product->getId());
        $esProduct->setAttributeSetId(self::PRODUCT_DEFAULT_ATTRIBUTE_SET_ID);
        $esProduct->setPrice($this->priceHelper->getItemPrice($product, $store));
        $esProduct->setFinalPrice($this->priceHelper->getItemPrice($product, $store));
        $esProduct->setStatus(self::PRODUCT_DEFAULT_STATUS);
        $esProduct->setVisibility(self::PRODUCT_DEFAULT_VISIBILITY);
        $esProduct->setTypeId(self::PRODUCT_SIMPLE_TYPE);
        $esProduct->setName($productName);
        $esProduct->setCreatedAt($this->getDateTime($product->getCreationDate()));
        $esProduct->setUpdatedAt($this->getDateTime($product->getModificationDate()));
        $esProduct->setStock($this->createStock($product));
        $esProduct->setEan($product->getEan());
        $esProduct->setAvailability(self::PRODUCT_DEFAULT_AVAILABILITY);
        $esProduct->setOptionTextStatus(self::PRODUCT_DEFAULT_OPTION_STATUS);
        $esProduct->setTaxClassId(self::PRODUCT_DEFAULT_TAX_CLASS_ID);
        $esProduct->setOptionTextTaxClassId(self::PRODUCT_DEFAULT_OPTION_CLASS_ID);
        $esProduct->setDescription($product->getDescription($language));
        $esProduct->setShortDescription($product->getShortDescription($language));
        $esProduct->setWeight($product->getWeight());
        $esProduct->setSku($product->getSku());
        $esProduct->setUrlKey($this->slugify->slugify($productName));
        $esProduct->setImage($product->getImage());

        $this->setMediaGallery($esProduct, $product->getImages());
        $this->setCategories($esProduct, $product, $language);

        return $esProduct;
    }

    public function getDocumentClass(): string
    {
        return Product::class;
    }

    /**
     * @param Product $esProduct
     * @param ProductInterface $product
     */
    private function setCategories(Product $esProduct, ProductInterface $product, ?string $language = null): void
    {
        $esProduct->getCategories()->clear();

        $defaultCat = new ProductCategory(self::PRODUCT_DEFAULT_CATEGORY, self::PRODUCT_DEFAULT_CATEGORY_ID);
        $esProduct->addCategory($defaultCat);

        // fetch all categories and their children
        $assignedCategories = [];
        $categories = $product->getCategories();
        foreach ($categories as $category) {
            $assignedCategories[] = $this->documentHelper->buildParents($category);
        }
        /** @var \CoreShop\Component\Core\Model\CategoryInterface $assignedCategories */
        $assignedCategories = array_merge([], ...$assignedCategories);

        // deduplicate and assign
        $categoryIds = [];
        foreach ($assignedCategories as $assignedCategory) {
            $id = $assignedCategory->getId();

            if (!in_array($id, $categoryIds, true)) {
                $categoryIds[] = $id;
                $esProduct->addCategory(new ProductCategory($assignedCategory->getName($language), $id));
            }
        }
        $esProduct->setCategoryIds($categoryIds);
    }

    /**
     * @param Product $product
     * @param array $images
     */
    private function setMediaGallery(Product $product, array $images): void
    {
        $product->getMediaGallery()->clear();
        $position = 1;

        /** @var Image $image */
        foreach ($images as $image) {
            if ($image->getRealFullPath()) {
                $product->addMediaGallery(
                    new MediaGallery($image->getRealFullPath(), $position++)
                );
            }
        }
    }

    /**
     * @param ProductInterface $product
     *
     * @return Stock
     */
    private function createStock(ProductInterface $product): Stock
    {
        $stock = new Stock();
        $stock->productId = $product->getId();
        $stock->itemId = $product->getId();
        $stock->isInStock = $product->getOnHand() > 0 ? true : false;
        $stock->qty = $product->getOnHand() ?: 0;
        $stock->isQtyDecimal = false;
        $stock->stockId = 1;

        if (null !== $minSaleQty = $product->getMinimumQuantityToOrder()) {
            $stock->useConfigMinSaleQty = true;
            $stock->minSaleQty = $minSaleQty;
        }
        if (null !== $maxSaleQty = $product->getMaximumQuantityToOrder()) {
            $stock->useConfigMaxSaleQty = true;
            $stock->maxSaleQty = $maxSaleQty;
        }

        return $stock;
    }
}
