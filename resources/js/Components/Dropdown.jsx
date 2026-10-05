import { Transition } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import { Children, cloneElement, createContext, isValidElement, useContext, useEffect, useId, useRef, useState } from 'react';

const DropDownContext = createContext();

const Dropdown = ({ children }) => {
    const [open, setOpen] = useState(false);
    const root = useRef(null);
    const contentId = useId();

    useEffect(() => {
        if (!open) return;
        const dismiss = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
                root.current?.querySelector('[data-dropdown-trigger]')?.focus();
            }
        };
        const outside = (event) => { if (!root.current?.contains(event.target)) setOpen(false); };
        document.addEventListener('keydown', dismiss);
        document.addEventListener('pointerdown', outside);
        return () => {
            document.removeEventListener('keydown', dismiss);
            document.removeEventListener('pointerdown', outside);
        };
    }, [open]);

    const toggleOpen = () => {
        setOpen((previousState) => !previousState);
    };

    return (
        <DropDownContext.Provider value={{ open, setOpen, toggleOpen, root, contentId }}>
            <div ref={root} className="relative" onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false); }}>{children}</div>
        </DropDownContext.Provider>
    );
};

const Trigger = ({ children }) => {
    const { open, toggleOpen, setOpen, root, contentId } = useContext(DropDownContext);
    const decorate = (element) => {
        if (!isValidElement(element)) return element;
        if (element.type === 'span' || element.type === 'div') {
            return cloneElement(element, {}, Children.map(element.props.children, decorate));
        }
        return cloneElement(element, {
            'aria-expanded': open,
            'aria-controls': contentId,
            'data-dropdown-trigger': true,
            onKeyDown: (event) => {
                element.props.onKeyDown?.(event);
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    setOpen(true);
                    requestAnimationFrame(() => root.current?.querySelector('[data-dropdown-content] a, [data-dropdown-content] button')?.focus());
                }
            },
        });
    };

    return (
        <>
            <div onClick={toggleOpen}>{Children.map(children, decorate)}</div>
        </>
    );
};

const Content = ({
    align = 'right',
    width = '48',
    contentClasses = 'py-1 bg-white',
    children,
}) => {
    const { open, setOpen, contentId } = useContext(DropDownContext);

    let alignmentClasses = 'origin-top';

    if (align === 'left') {
        alignmentClasses = 'ltr:origin-top-left rtl:origin-top-right start-0';
    } else if (align === 'right') {
        alignmentClasses = 'ltr:origin-top-right rtl:origin-top-left end-0';
    }

    let widthClasses = '';

    if (width === '48') {
        widthClasses = 'w-48 max-w-[calc(100vw-2rem)]';
    } else if (width === '64') {
        widthClasses = 'w-64 max-w-[calc(100vw-2rem)]';
    }

    return (
        <>
            <Transition
                show={open}
                enter="transition ease-out duration-200"
                enterFrom="opacity-0 scale-95"
                enterTo="opacity-100 scale-100"
                leave="transition ease-in duration-75"
                leaveFrom="opacity-100 scale-100"
                leaveTo="opacity-0 scale-95"
            >
                <div
                    id={contentId}
                    data-dropdown-content
                    className={`absolute z-50 mt-2 rounded-lg shadow-lg ring-1 ring-gray-200 ${alignmentClasses} ${widthClasses}`}
                    onClick={() => setOpen(false)}
                >
                    <div
                        className={
                            `rounded-lg overflow-hidden ` +
                            contentClasses
                        }
                    >
                        {children}
                    </div>
                </div>
            </Transition>
        </>
    );
};

const DropdownLink = ({ className = '', children, ...props }) => {
    return (
        <Link
            {...props}
            className={
                // focus:bg-gray-50 was the only focus signal, and gray-50 on white
                // is 1.04:1 — inside the 19-link Insights menu a keyboard user was
                // moving blind. The ring must be inset: Content wraps its children
                // in `rounded-lg overflow-hidden`, which clips an outset ring off
                // the first and last item.
                'block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-50 focus-visible:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-primary ' +
                className
            }
        >
            {children}
        </Link>
    );
};

const DropdownHeader = ({ children }) => (
    <p className="px-4 pt-2 pb-1 text-xs font-semibold text-gray-500 uppercase tracking-wider">
        {children}
    </p>
);

const DropdownDivider = () => (
    <div className="my-1 border-t border-gray-100" />
);

Dropdown.Trigger = Trigger;
Dropdown.Content = Content;
Dropdown.Link = DropdownLink;
Dropdown.Header = DropdownHeader;
Dropdown.Divider = DropdownDivider;

export default Dropdown;
