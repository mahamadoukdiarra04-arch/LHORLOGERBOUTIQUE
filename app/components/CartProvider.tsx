"use client";

import { createContext, useContext, useEffect, useMemo, useState } from "react";

export type CartLine = {
  key: string;
  productId: string;
  quantity: number;
  variant?: string;
  bracelet?: string;
};

type CartContextValue = {
  lines: CartLine[];
  itemCount: number;
  addToCart: (productId: string, quantity?: number, variant?: string) => void;
  updateQuantity: (key: string, quantity: number) => void;
  removeFromCart: (key: string) => void;
  clearCart: () => void;
};

const CartContext = createContext<CartContextValue | null>(null);
const storageKey = "tuma-cart";

export function CartProvider({ children }: { children: React.ReactNode }) {
  const [lines, setLines] = useState<CartLine[]>([]);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(storageKey);
      if (saved) setLines(JSON.parse(saved) as CartLine[]);
    } catch {
      window.localStorage.removeItem(storageKey);
    } finally {
      setReady(true);
    }
  }, []);

  useEffect(() => {
    if (ready) window.localStorage.setItem(storageKey, JSON.stringify(lines));
  }, [lines, ready]);

  const value = useMemo<CartContextValue>(() => {
    const addToCart = (productId: string, quantity = 1, variant?: string) => {
      const key = `${productId}:${variant ?? "standard"}`;
      setLines((current) => {
        const existing = current.find((line) => line.key === key);
        if (!existing) return [...current, { key, productId, quantity, variant }];
        return current.map((line) =>
          line.key === key ? { ...line, quantity: line.quantity + quantity } : line,
        );
      });
    };

    return {
      lines,
      itemCount: lines.reduce((total, line) => total + line.quantity, 0),
      addToCart,
      updateQuantity: (key, quantity) =>
        setLines((current) =>
          quantity < 1
            ? current.filter((line) => line.key !== key)
            : current.map((line) => (line.key === key ? { ...line, quantity } : line)),
        ),
      removeFromCart: (key) => setLines((current) => current.filter((line) => line.key !== key)),
      clearCart: () => setLines([]),
    };
  }, [lines]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const cart = useContext(CartContext);
  if (!cart) throw new Error("useCart must be used inside CartProvider");
  return cart;
}
