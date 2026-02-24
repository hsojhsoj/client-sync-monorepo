/**
 * Mock for @wordpress/element — re-exports React.
 */
const React = require('react');

module.exports = {
	...React,
	createElement: React.createElement,
	Fragment: React.Fragment,
	createContext: React.createContext,
	forwardRef: React.forwardRef,
	lazy: React.lazy,
	memo: React.memo,
	Suspense: React.Suspense,
	useState: React.useState,
	useEffect: React.useEffect,
	useContext: React.useContext,
	useReducer: React.useReducer,
	useCallback: React.useCallback,
	useMemo: React.useMemo,
	useRef: React.useRef,
	useLayoutEffect: React.useLayoutEffect,
	useImperativeHandle: React.useImperativeHandle,
	useDebugValue: React.useDebugValue,
};
