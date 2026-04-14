(function (global) {
  const cache = Object.create(null);
  const inflight = Object.create(null);

  const ENDPOINT = 'db/products_getAll.php';

  function clone(data) {
    if (typeof structuredClone === 'function') {
      try {
        return structuredClone(data);
      } catch (err) {
        // fall back to JSON clone
      }
    }
    return data == null ? data : JSON.parse(JSON.stringify(data));
  }

  function extractList(data) {
    if (data && Array.isArray(data.products)) {
      return data.products;
    }
    if (Array.isArray(data)) {
      return data;
    }
    return [];
  }

  function normalizeSize(size) {
    const id = size.id ?? size.sizeID ?? size.size_id ?? null;
    const name = size.name ?? size.sizeName ?? size.size_name ?? '';
    const price = Number(size.price ?? size.defaultPrice ?? 0);

    return {
      id,
      name,
      price,
      sizeID: id,
      sizeName: name,
      size_label: name,
      defaultPrice: price
    };
  }

  function normalizeUnits(units) {
    if (!Array.isArray(units)) return [];
    return units.map(unit => {
      const id = unit.id ?? unit.unitID ?? unit.unit_id ?? null;
      const name = unit.name ?? unit.unitName ?? unit.unit_name ?? '';
      const symbol = unit.symbol ?? unit.unitSymbol ?? unit.unit_symbol ?? '';
      return {
        id,
        name,
        symbol,
        unitID: id,
        unit_name: name,
        unit_symbol: symbol
      };
    });
  }

  function normalizeProducts(data) {
    return extractList(data).map(item => {
      const id = item.id ?? item.productID ?? item.product_id ?? null;
      const name = item.name ?? item.productName ?? item.product_name ?? '';
      const categoryId = item.category?.id ?? item.categoryID ?? item.category_id ?? null;
      const categoryName = item.category?.name ?? item.categoryName ?? item.category_name ?? '';
      const image = item.image ?? item.image_url ?? '';
      const description = item.description ?? '';
      const isActive = typeof item.isActive === 'boolean' ? item.isActive : Boolean(item.isActive ?? item.active);
      const createdAt = item.createdAt ?? item.created_at ?? null;
      const sizes = Array.isArray(item.sizes) ? item.sizes.map(normalizeSize) : [];
      const units = normalizeUnits(item.units);
      const hasAddons = Boolean(item.has_addons ?? item.hasAddons);
      const hasFlavors = Boolean(item.has_flavors ?? item.hasFlavors);
      const addons = Array.isArray(item.addons) ? item.addons : [];
      const flavors = Array.isArray(item.flavors) ? item.flavors : [];

      return {
        id,
        name,
        description,
        category: {
          id: categoryId,
          name: categoryName
        },
        image,
        isActive,
        createdAt,
        sizes,
        units,
        has_addons: hasAddons,
        has_flavors: hasFlavors,
        addons,
        flavors,
        // legacy compatibility fields
        productID: id,
        productName: name,
        categoryID: categoryId,
        categoryName,
        image_url: image,
        created_at: createdAt
      };
    });
  }

  function transformProducts(data, options) {
    const { format, includeInactive } = options;
    const normalized = normalizeProducts(data);

    switch (format) {
      case 'pos':
        return normalized
          .filter(product => includeInactive || product.isActive)
          .map(product => ({
            id: product.id,
            name: product.name,
            category: product.category.name,
            categoryID: product.category.id,
            size: product.sizes.reduce((acc, size) => {
              acc[size.name] = size.price;
              return acc;
            }, {}),
            sizes: product.sizes
          }));
      case 'raw':
        return data;
      case 'normalized':
      case 'list':
      case 'default':
      default:
        return normalized;
    }
  }

  function fetchProducts(options = {}) {
    const {
      force = false,
      includeInactive = false,
      includeUnits = false,
      format = 'default',
      status = ''
    } = options;

    const key = JSON.stringify({ includeInactive, includeUnits, format, status });

    if (!force && Object.prototype.hasOwnProperty.call(cache, key)) {
      return Promise.resolve(clone(cache[key]));
    }

    if (inflight[key]) {
      return inflight[key].then(clone);
    }

    const params = new URLSearchParams();
    if (includeInactive) params.append('includeInactive', '1');
    if (includeUnits) params.append('includeUnits', '1');
    if (status) params.append('status', status);
    if (format === 'raw') params.append('format', 'array');
    else params.append('format', 'payload');

    const query = params.toString();
    const url = query ? `${ENDPOINT}?${query}` : ENDPOINT;

    inflight[key] = fetch(url, { cache: 'no-store' })
      .then(response => {
        if (!response.ok) {
          throw new Error(`Request failed: ${response.status} ${response.statusText}`);
        }
        return response.json();
      })
      .then(data => {
        const processed = transformProducts(data, { format, includeInactive });
        cache[key] = processed;
        return processed;
      })
      .finally(() => {
        delete inflight[key];
      });

    return inflight[key].then(clone);
  }

  function invalidateProducts() {
    Object.keys(cache).forEach(key => delete cache[key]);
  }

  global.ProductService = {
    fetchProducts,
    invalidateProducts
  };
})(window);
